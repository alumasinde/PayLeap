<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\Passwords;
use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\FileMailer;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Migrator;
use Mshahara\Payroll\Support\Sessions\DatabaseHandler;
use Mshahara\Payroll\Support\SignedUrl;
use Mshahara\Payroll\Support\Token;

ob_start();

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Env::load($root . '/.env');

$database = 'mshahara_payroll_test';
putenv('DB_DATABASE=' . $database);
putenv('APP_ENV=test');

$config = require $root . '/config/app.php';
$server = Db::server($config['db']);
Migrator::dropDatabase($server, $database);
Migrator::createDatabase($server, $database);

$db = Db::connect($config['db']);
$existingTables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
(new Migrator($db, $root . '/migrations'))->run();

// Sessions are database-backed from 0002 onward, so migrate before boot.
$app = App::boot($root);

$checks = 0;
$failures = 0;

check('test database starts empty', $existingTables === [], implode(', ', $existingTables));

function check(string $label, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;

    if (!$ok) {
        $failures++;
    }

    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

// ------------------------------------------------------------------ invitations

$companyId = $app->companies->create('Test Co', 'test-co');
$invite = $app->invitations->create($companyId, 'Ada@Example.test', 'hr');

check('invite token is long and random', strlen($invite['token']) >= 40);

$row = $app->invitations->find($invite['token']);
check('invite resolves, email normalised', $row !== null && $row['email'] === 'ada@example.test');

$user = $app->invitations->acceptNew($row, 'Ada Lovelace', 'correct horse battery');
check('accepting creates an active user', $user['status'] === 'active');
check('email is marked verified on accept', $user['email_verified_at'] !== null);

check('accepted invite no longer resolves', $app->invitations->find($invite['token']) === null);

$replayBlocked = false;
try {
    $app->invitations->acceptNew($row, 'Impostor', 'another password 1');
} catch (HttpException $error) {
    $replayBlocked = $error->status === 409;
}
check('accepted invite cannot be replayed', $replayBlocked);

$memberships = $app->companies->membershipsFor((int) $user['id']);
check('membership role comes from the invite row', count($memberships) === 1 && $memberships[0]['role'] === 'hr');

$expired = $app->invitations->create($companyId, 'expired@example.test', 'employee');
$app->db
    ->prepare('UPDATE invitations SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?')
    ->execute([(int) $expired['invitation']['id']]);
check('expired invite is rejected', $app->invitations->find($expired['token']) === null);

$second = $app->invitations->create($companyId, 'temp@example.test', 'employee');
$secondRow = $app->invitations->find($second['token']);
$tempUser = $app->invitations->acceptNew($secondRow, 'Temp Person', 'temporary password 1');
$tempMembership = $app->companies->membershipsFor((int) $tempUser['id']);
check('role cannot be elevated by request data', $tempMembership[0]['role'] === 'employee');

$otherCompany = $app->companies->create('Other Co', 'other-co');
$otherInvite = $app->invitations->create($otherCompany, 'ada@example.test', 'hod');
$otherRow = $app->invitations->find($otherInvite['token']);

$wrongEmailBlocked = false;
try {
    $app->invitations->acceptExisting($otherRow, ['id' => 999999, 'email' => 'someone@else.test']);
} catch (HttpException $error) {
    $wrongEmailBlocked = $error->status === 403;
}
check('invite refuses a different signed-in email', $wrongEmailBlocked);

$app->invitations->acceptExisting($otherRow, $user);
$secondMembership = $app->companies->membershipsFor((int) $user['id']);
check('same account can join a second company', count($secondMembership) === 2);

$otherRole = null;
foreach ($secondMembership as $membership) {
    if ((int) $membership['company_id'] === $otherCompany) {
        $otherRole = $membership['role'];
    }
}
check('second company keeps its own role', $otherRole === 'hod');

// ---------------------------------------------------------------------- resets

$token = $app->resets->request('ada@example.test');
check('reset token is issued', is_string($token) && strlen((string) $token) >= 40);

$reset = $app->resets->find((string) $token);
check('reset token resolves', $reset !== null);
check('old password works before reset', $app->auth->attempt('ada@example.test', 'correct horse battery') !== null);

$epochBefore = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $user['id'])->fetchColumn();
$app->resets->complete($reset, 'a newer password 2');
$epochAfter = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $user['id'])->fetchColumn();

check('reset token is single use', $app->resets->find((string) $token) === null);
check('old password rejected after reset', $app->auth->attempt('ada@example.test', 'correct horse battery') === null);
check('new password accepted after reset', $app->auth->attempt('ada@example.test', 'a newer password 2') !== null);
check('reset bumps the session epoch', $epochAfter === $epochBefore + 1);

$reusedReset = $app->resets->request('ada@example.test');
$app->db
    ->prepare('UPDATE password_resets SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE token_hash = ?')
    ->execute([Token::hash((string) $reusedReset)]);
check('expired reset is rejected', $app->resets->find((string) $reusedReset) === null);
check('unknown email gets no token', $app->resets->request('nobody@example.test') === null);

// ------------------------------------------------------------------------ csrf

$app->session->replace([]);
$csrf = $app->csrf->token();
check('csrf accepts its own token', $app->csrf->check($csrf));
check('csrf rejects a different token', !$app->csrf->check(Token::generate()));
check('csrf rejects a missing token', !$app->csrf->check(null));

// ---------------------------------------------------------------- rate limiting

$app->limiter->clear('test-bucket', 'key');
$results = [];
for ($i = 0; $i < 4; $i++) {
    $results[] = $app->limiter->hit('test-bucket', 'key', 3, 60);
}
check('rate limiter allows the max then blocks', $results === [true, true, true, false]);

// ------------------------------------------------------------------ permissions

check('hr cannot run payroll', !$app->gate->allows('hr', 'payroll.run'));
check('finance sees bank but cannot edit people', $app->gate->allows('finance', 'employees.bank.view') && !$app->gate->allows('finance', 'employees.edit'));
check('hod cannot see salary', !$app->gate->allows('hod', 'employees.salary.view'));
check('owner is a wildcard', $app->gate->allows('owner', 'anything.at.all'));

// ------------------------------------------------------------------ signed urls

$signed = new SignedUrl('test-key', 'https://example.test');
$url = $signed->make('/export', ['run' => 7], 60);
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
check('signed url verifies', $signed->verify('/export', $query));

$tampered = $query;
$tampered['run'] = 8;
check('tampered signed url is rejected', !$signed->verify('/export', $tampered));

$expiredUrl = $signed->make('/export', [], -5);
parse_str((string) parse_url($expiredUrl, PHP_URL_QUERY), $expiredQuery);
check('expired signed url is rejected', !$signed->verify('/export', $expiredQuery));

// -------------------------------------------------------------------- sessions

$fresh = $app->db->query('SELECT * FROM users WHERE id = ' . (int) $user['id'])->fetch();
$before = $app->session->id();
$app->auth->startSession($fresh);
$after = $app->session->id();

check('session id rotates on login', $before !== '' && $after !== '' && $before !== $after);
check('session authenticates after login', $app->auth->user() !== null);

$app->session->put('epoch', 0);
check('stale session epoch is rejected', $app->auth->user() === null);

$app->auth->startSession($fresh);
$app->session->put('last_seen', time() - 3600);
check('idle session expires', $app->auth->user() === null);

// ----------------------------------------------------------- session storage

$app->auth->startSession($fresh);
$app->session->put('probe', 'kept');
$sid = $app->session->id();
session_write_close();

$handler = new DatabaseHandler($app->db);
check('database session row is written', str_contains((string) $handler->read($sid), 'probe'));

$userIdStatement = $app->db->prepare('SELECT user_id FROM sessions WHERE id = ?');
$userIdStatement->execute([$sid]);
check('database session row carries the user id', (int) $userIdStatement->fetchColumn() === (int) $fresh['id']);

$handler->destroy($sid);
check('database session row is deleted', $handler->read($sid) === '');

// ------------------------------------------------------ sign out everywhere

$epochBefore = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $fresh['id'])->fetchColumn();
$app->db->prepare('INSERT INTO sessions (id, user_id, data, last_activity) VALUES (?, ?, ?, ?)')
    ->execute(['probe-' . bin2hex(random_bytes(4)), (int) $fresh['id'], 'probe', time()]);

$app->auth->logoutEverywhere((int) $fresh['id']);

$remaining = (int) $app->db->query('SELECT COUNT(*) FROM sessions WHERE user_id = ' . (int) $fresh['id'])->fetchColumn();
$epochAfter = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $fresh['id'])->fetchColumn();

check('sign out everywhere deletes session rows', $remaining === 0);
check('sign out everywhere bumps the epoch', $epochAfter === $epochBefore + 1);

// ----------------------------------------------------------------------- audit

$actions = $app->db->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
check('audit records invite creation', in_array('invite.created', $actions, true));
check('audit records acceptance', in_array('invite.accepted', $actions, true));
check('audit records reset completion', in_array('password.reset.completed', $actions, true));

// --------------------------------------------------------------------- password

check('short passwords are rejected', Passwords::error('short', 'short') !== null);
check('mismatched passwords are rejected', Passwords::error('long enough 1', 'long enough 2') !== null);
check('a valid password passes', Passwords::error('long enough 1', 'long enough 1') === null);

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All auth checks passed.\n";
