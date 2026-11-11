<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\PasswordHasher;
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

$companyId = $app->companyService->create('Test Co', 'test-co');
$invite = $app->invitationService->create($companyId, 'Ada@Example.test', 'hr');

check('invite token is long and random', strlen($invite['token']) >= 40);

$row = $app->invitationService->find($invite['token']);
check('invite resolves, email normalised', $row !== null && $row['email'] === 'ada@example.test');

$user = $app->invitationService->acceptNew($row, 'Ada', 'Lovelace', 'correct horse battery');
check('accepting creates an active user', $user['status'] === 'active');
check('name is stored as first and last', $user['first_name'] === 'Ada' && $user['last_name'] === 'Lovelace');
check('email is marked verified on accept', $user['email_verified_at'] !== null);

check('accepted invite no longer resolves', $app->invitationService->find($invite['token']) === null);

$replayBlocked = false;
try {
    $app->invitationService->acceptNew($row, 'Impostor', 'Person', 'another password 1');
} catch (HttpException $error) {
    $replayBlocked = $error->status === 409;
}
check('accepted invite cannot be replayed', $replayBlocked);

$memberships = $app->companyRepository->membershipsFor((int) $user['id']);
check('membership role comes from the invite row', count($memberships) === 1 && $memberships[0]['role_names'] === 'HR');

$expired = $app->invitationService->create($companyId, 'expired@example.test', 'employee');
$app->db
    ->prepare('UPDATE invitations SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?')
    ->execute([(int) $expired['invitation']['id']]);
check('expired invite is rejected', $app->invitationService->find($expired['token']) === null);

$second = $app->invitationService->create($companyId, 'temp@example.test', 'employee');
$secondRow = $app->invitationService->find($second['token']);
$tempUser = $app->invitationService->acceptNew($secondRow, 'Temp', 'Person', 'temporary password 1');
$tempMembership = $app->companyRepository->membershipsFor((int) $tempUser['id']);
check('role cannot be elevated by request data', $tempMembership[0]['role_names'] === 'Employee');

$otherCompany = $app->companyService->create('Other Co', 'other-co');
$otherInvite = $app->invitationService->create($otherCompany, 'ada@example.test', 'hod');
$otherRow = $app->invitationService->find($otherInvite['token']);

$wrongEmailBlocked = false;
try {
    $app->invitationService->acceptExisting($otherRow, ['id' => 999999, 'email' => 'someone@else.test']);
} catch (HttpException $error) {
    $wrongEmailBlocked = $error->status === 403;
}
check('invite refuses a different signed-in email', $wrongEmailBlocked);

$app->invitationService->acceptExisting($otherRow, $user);
$secondMembership = $app->companyRepository->membershipsFor((int) $user['id']);
check('same account can join a second company', count($secondMembership) === 2);

$otherRole = null;
foreach ($secondMembership as $membership) {
    if ((int) $membership['company_id'] === $otherCompany) {
        $otherRole = $membership['role_names'];
    }
}
check('second company keeps its own role', $otherRole === 'Head of department');

// ----------------------------------------------------------- temporary password

$epochBefore = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $user['id'])->fetchColumn();
$temporary = $app->passwordResetService->request('ada@example.test');
$epochAfter = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $user['id'])->fetchColumn();

check('temporary password is six characters', is_string($temporary) && strlen((string) $temporary) === 6);
check('temporary password avoids ambiguous characters', preg_match('/^[A-HJ-NP-Z2-9]{6}$/', (string) $temporary) === 1);
check('temporary password bumps the session epoch', $epochAfter === $epochBefore + 1);

$tempLogin = $app->authentication->attempt('ada@example.test', (string) $temporary);
check('temporary password signs in', $tempLogin !== null);
check('temporary login is flagged for a change', $tempLogin !== null && (int) $tempLogin['must_change_password'] === 1);
check('old password rejected after temporary password', $app->authentication->attempt('ada@example.test', 'correct horse battery') === null);
check('unknown email gets no temporary password', $app->passwordResetService->request('nobody@example.test') === null);

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

$hrRole = $app->roleRepository->findByKey($companyId, 'hr');
$financeRole = $app->roleRepository->findByKey($companyId, 'finance');
$hodRole = $app->roleRepository->findByKey($companyId, 'hod');
$ownerRole = $app->roleRepository->findByKey($companyId, 'owner');

check('company roles are cloned from the templates', $hrRole !== null && (int) $hrRole['company_id'] === $companyId);
check('invite assigned the user role', in_array('hr', array_column($app->roleRepository->rolesForUser((int) $user['id'], $companyId), 'key'), true));

$financePermissions = $app->roleRepository->permissionsFor((int) $financeRole['id']);
check('finance sees bank but cannot edit people', in_array('employees.bank.view', $financePermissions, true) && !in_array('employees.edit', $financePermissions, true));
check('hod cannot see salary', !in_array('employees.salary.view', $app->roleRepository->permissionsFor((int) $hodRole['id']), true));
check('owner has every permission', count($app->roleRepository->permissionsFor((int) $ownerRole['id'])) === (int) $app->db->query('SELECT COUNT(*) FROM permissions')->fetchColumn());

check('hr cannot run payroll', !$app->permissionGate->allows((int) $user['id'], $companyId, 'payroll.run'));
check('hr can see salary', $app->permissionGate->allows((int) $user['id'], $companyId, 'employees.salary.view'));

$app->roleRepository->deny((int) $hrRole['id'], 'employees.salary.view');
$app->permissionGate->forget();
check('a company can take a permission out of a role', !$app->permissionGate->allows((int) $user['id'], $companyId, 'employees.salary.view'));

$app->roleRepository->grant((int) $hrRole['id'], 'employees.salary.view');
$app->permissionGate->forget();
check('a company can add it back', $app->permissionGate->allows((int) $user['id'], $companyId, 'employees.salary.view'));

// ------------------------------------------------------------ access management

$app->membershipService->changeRole($companyId, (int) $tempUser['id'], (int) $hrRole['id'], (int) $user['id']);
$tempRoles = array_column($app->roleRepository->rolesForUser((int) $tempUser['id'], $companyId), 'key');
check('changing a role replaces the assignment', $tempRoles === ['hr']);

$selfBlocked = false;
try {
    $app->membershipService->changeRole($companyId, (int) $user['id'], (int) $hrRole['id'], (int) $user['id']);
} catch (HttpException $error) {
    $selfBlocked = $error->status === 403;
}
check('cannot change your own role', $selfBlocked);

$ownerInvite = $app->invitationService->create($companyId, 'owner@example.test', 'owner');
$ownerRow = $app->invitationService->find($ownerInvite['token']);
$owner = $app->invitationService->acceptNew($ownerRow, 'Olive', 'Owner', 'owner password 1');

$lastOwnerBlocked = false;
try {
    $app->membershipService->changeRole($companyId, (int) $owner['id'], (int) $hrRole['id'], (int) $tempUser['id']);
} catch (HttpException $error) {
    $lastOwnerBlocked = $error->status === 409;
}
check('last owner cannot be demoted', $lastOwnerBlocked);

$app->membershipService->remove($companyId, (int) $tempUser['id'], (int) $user['id']);
check('removing a member ends the membership', $app->companyRepository->membership((int) $tempUser['id'], $companyId) === null);

// --------------------------------------------------------------- role editor

$app->roleService->update($companyId, (int) $hrRole['id'], 'People team', [
    'employees.view',
    'employees.salary.view',
], (int) $user['id']);
$app->permissionGate->forget();

$updatedHr = $app->roleRepository->find((int) $hrRole['id']);
check('role editor renames a role', $updatedHr['name'] === 'People team');
check('role editor replaces permissions', $app->roleRepository->permissionsFor((int) $hrRole['id']) === ['employees.salary.view', 'employees.view']);
check('removed permission is enforced', !$app->permissionGate->allows((int) $user['id'], $companyId, 'users.invite'));

// -------------------------------------------------------------------- account

$app->accountService->updateProfile((int) $user['id'], 'Ada', 'Byron', $companyId);
$updatedUser = $app->userRepository->find((int) $user['id']);
check('profile updates first and last name', $updatedUser['first_name'] === 'Ada' && $updatedUser['last_name'] === 'Byron');

$wrongCurrent = false;
try {
    $app->accountService->changePassword((int) $user['id'], 'wrong password', 'changed password 3', 'changed password 3', $companyId);
} catch (HttpException $error) {
    $wrongCurrent = $error->status === 422;
}
check('password change requires the current password', $wrongCurrent);

$epoch = $app->accountService->changePassword((int) $user['id'], (string) $temporary, 'changed password 3', 'changed password 3', $companyId);
check('password change bumps the session epoch', $epoch >= 2);
check('new password authenticates', $app->authentication->attempt('ada@example.test', 'changed password 3') !== null);
check(
    'password change clears the temporary flag',
    (int) $app->db->query('SELECT must_change_password FROM users WHERE id = ' . (int) $user['id'])->fetchColumn() === 0,
);

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
$app->authentication->startSession($fresh);
$after = $app->session->id();

check('session id rotates on login', $before !== '' && $after !== '' && $before !== $after);
check('session authenticates after login', $app->authentication->user() !== null);

$app->session->put('epoch', 0);
check('stale session epoch is rejected', $app->authentication->user() === null);

$app->authentication->startSession($fresh);
$app->session->put('last_seen', time() - 3600);
check('idle session expires', $app->authentication->user() === null);

// ----------------------------------------------------------- session storage

$app->authentication->startSession($fresh);
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

$app->authentication->logoutEverywhere((int) $fresh['id']);

$remaining = (int) $app->db->query('SELECT COUNT(*) FROM sessions WHERE user_id = ' . (int) $fresh['id'])->fetchColumn();
$epochAfter = (int) $app->db->query('SELECT session_epoch FROM users WHERE id = ' . (int) $fresh['id'])->fetchColumn();

check('sign out everywhere deletes session rows', $remaining === 0);
check('sign out everywhere bumps the epoch', $epochAfter === $epochBefore + 1);

// ----------------------------------------------------------------------- audit

$actions = $app->db->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
check('audit records invite creation', in_array('invite.created', $actions, true));
check('audit records acceptance', in_array('invite.accepted', $actions, true));
check('audit records a temporary password', in_array('password.temp.issued', $actions, true));

// --------------------------------------------------------------------- password

check('short passwords are rejected', PasswordHasher::error('short', 'short') !== null);
check('seven characters are rejected', PasswordHasher::error('1234567', '1234567') !== null);
check('eight characters are accepted', PasswordHasher::error('12345678', '12345678') === null);
check('mismatched passwords are rejected', PasswordHasher::error('long enough 1', 'long enough 2') !== null);
check('a valid password passes', PasswordHasher::error('long enough 1', 'long enough 1') === null);

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All auth checks passed.\n";
