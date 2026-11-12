<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\Migrator;

ob_start();

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
putenv('DB_DATABASE=mshahara_payroll_test');
putenv('APP_ENV=test');

$config = require $root . '/config/app.php';
$server = Db::server($config['db']);
Migrator::dropDatabase($server, 'mshahara_payroll_test');
Migrator::createDatabase($server, 'mshahara_payroll_test');
$db = Db::connect($config['db']);
(new Migrator($db, $root . '/migrations'))->run();
$app = App::boot($root);

$checks = 0;
$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;

    if (!$ok) {
        $failures++;
    }

    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
}

$companyId = $app->companyService->create('Attendance Co', 'attendance-co');
$invite = $app->invitationService->create($companyId, 'owner@attendance.test', 'owner');
$owner = $app->invitationService->acceptNew(
    $app->invitationService->find($invite['token']),
    'Ada',
    'Owner',
    'correct horse battery',
);
$ownerId = (int) $owner['id'];

$employee = static function (string $first, string $last) use ($app, $companyId, $ownerId): int {
    return $app->employees->service->create($companyId, [
        'first_name' => $first,
        'last_name' => $last,
        'start_date' => '2024-01-01',
    ], $ownerId);
};

$presentId = $employee('Pres', 'Ent');
$absentId = $employee('Ab', 'Sent');
$leaveId = $employee('On', 'Leave');
$unmarkedId = $employee('Un', 'Marked');
$date = (new DateTimeImmutable('next monday'))->format('Y-m-d');
$futureId = $app->employees->service->create($companyId, [
    'first_name' => 'Future',
    'last_name' => 'Starter',
    'start_date' => (new DateTimeImmutable($date))->modify('+30 days')->format('Y-m-d'),
], $ownerId);

$app->attendance->service->mark($companyId, $presentId, $date, 'present', '08:00', '17:00', $ownerId);
$app->attendance->service->mark($companyId, $absentId, $date, 'absent', null, null, $ownerId);

$type = $db->prepare('SELECT id FROM leave_types WHERE company_id = ? AND system_code = ? LIMIT 1');
$type->execute([$companyId, 'annual']);
$leaveTypeId = (int) $type->fetchColumn();
$db->prepare(
    'INSERT INTO leave_requests (company_id, employee_id, leave_type_id, start_date, end_date, days, status)
     VALUES (?, ?, ?, ?, ?, 1, "approved")',
)->execute([$companyId, $leaveId, $leaveTypeId, $date, $date]);

$marked = $app->attendance->service->markUnmarkedPresent($companyId, $date, $ownerId);
check('bulk close-out marks only the unmarked active employee', $marked === 1);

$rows = $app->attendance->records->day($companyId, $date);
$byId = [];
foreach ($rows as $row) {
    $byId[(int) $row['id']] = $row;
}
check('an existing present mark is not overwritten', $byId[$presentId]['attendance_status'] === 'present');
check('an existing absence is not overwritten', $byId[$absentId]['attendance_status'] === 'absent');
check('approved leave is not marked present', $byId[$leaveId]['attendance_status'] === null);
check('the unmarked employee becomes present', $byId[$unmarkedId]['attendance_status'] === 'present');
check('a future starter is not on the day register', !isset($byId[$futureId]));

$again = $app->attendance->service->markUnmarkedPresent($companyId, $date, $ownerId);
check('a second close-out is a no-op', $again === 0
    && count(array_filter($app->attendance->records->day($companyId, $date), static fn (array $row): bool => $row['attendance_status'] !== null)) === 3);

$action = $db->prepare('SELECT COUNT(*) FROM audit_log WHERE company_id = ? AND action = ?');
$action->execute([$companyId, 'attendance.bulk_marked']);
check('bulk close-out is audited', (int) $action->fetchColumn() === 2);

$otherCompany = $app->companyService->create('Other Attendance Co', 'other-attendance-co');
$otherInvite = $app->invitationService->create($otherCompany, 'other@attendance.test', 'owner');
$otherOwner = $app->invitationService->acceptNew(
    $app->invitationService->find($otherInvite['token']),
    'Ola',
    'Other',
    'correct horse battery',
);
$app->employees->service->create($otherCompany, [
    'first_name' => 'Else',
    'last_name' => 'Where',
    'start_date' => '2024-01-01',
], (int) $otherOwner['id']);
$app->attendance->service->markUnmarkedPresent($companyId, $date, $ownerId);
$otherRows = $app->attendance->records->day($otherCompany, $date);
check('bulk close-out is company scoped', $otherRows[0]['attendance_status'] === null);

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All attendance checks passed.\n";
