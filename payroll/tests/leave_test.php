<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Migrator;

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

/** @return int status code of the exception, or 0 when nothing was thrown */
function statusOf(callable $action): int
{
    try {
        $action();
    } catch (HttpException $error) {
        return $error->status;
    }

    return 0;
}

$companyId = $app->companyService->create('Test Co', 'test-co');
$service = $app->employees->service;
$register = $app->employees->register;

$operations = $service->addDepartment($companyId, 'Operations', 1);
$finance = $service->addDepartment($companyId, 'Finance', 1);

$inviteHod = $app->invitationService->create($companyId, 'hod@example.test', 'hod');
$hod = $app->invitationService->acceptNew(
    $app->invitationService->find($inviteHod['token']),
    'Hilda',
    'Head',
    'correct horse battery',
);
$hodUserId = (int) $hod['id'];

$inviteHr = $app->invitationService->create($companyId, 'hr@example.test', 'hr');
$hr = $app->invitationService->acceptNew(
    $app->invitationService->find($inviteHr['token']),
    'Harriet',
    'Reed',
    'correct horse battery',
);
$hrUserId = (int) $hr['id'];

$makeEmployee = static function (array $input) use ($service, $companyId): int {
    return $service->create($companyId, $input + [
        'start_date' => '2024-01-01',
        'employment_type' => 'permanent',
    ], 1);
};

$hodEmployee = $makeEmployee([
    'first_name' => 'Hilda',
    'last_name' => 'Head',
    'department_id' => $operations,
    'user_id' => $hodUserId,
]);
$staffA = $makeEmployee([
    'first_name' => 'Amina',
    'last_name' => 'Otieno',
    'department_id' => $operations,
    'manager_id' => $hodEmployee,
]);
$staffB = $makeEmployee([
    'first_name' => 'Brian',
    'last_name' => 'Kariuki',
    'department_id' => $finance,
]);
$reportAcross = $makeEmployee([
    'first_name' => 'Cate',
    'last_name' => 'Mwangi',
    'department_id' => $finance,
    'manager_id' => $hodEmployee,
]);
$hrEmployee = $makeEmployee([
    'first_name' => 'Harriet',
    'last_name' => 'Reed',
    'department_id' => $finance,
    'user_id' => $hrUserId,
]);
$gm = $makeEmployee([
    'first_name' => 'George',
    'last_name' => 'Manager',
    'department_id' => $finance,
]);
$service->update($companyId, $hodEmployee, ['manager_id' => $gm], 1);

$annual = $app->leave->requests->types($companyId)[0];
$annualId = (int) $annual['id'];

$request = static function (int $employeeId) use ($db, $companyId, $annualId): int {
    $db->prepare(
        'INSERT INTO leave_requests
            (company_id, employee_id, leave_type_id, start_date, end_date, days, status)
         VALUES (?, ?, ?, ?, ?, ?, \'pending\')',
    )->execute([$companyId, $employeeId, $annualId, '2026-12-01', '2026-12-03', 3]);

    return (int) $db->lastInsertId();
};

$aRequest = $request($staffA);
$bRequest = $request($staffB);
$reportRequest = $request($reportAcross);
$hodRequest = $request($hodEmployee);
$hrRequest = $request($hrEmployee);
$gmRequest = $request($gm);

$leave = $app->leave->service;

// ------------------------------------------------------------- permissions

check(
    'HOD may approve team leave',
    $app->permissionGate->allows($hodUserId, $companyId, 'leave.approve.team'),
);
check(
    'HOD may not approve company leave',
    !$app->permissionGate->allows($hodUserId, $companyId, 'leave.approve.all'),
);
check(
    'HR may approve company leave',
    $app->permissionGate->allows($hrUserId, $companyId, 'leave.approve.all'),
);

// ------------------------------------------------------------------- scope

$hodScope = $leave->scopeFor($companyId, $hodUserId, false, true);
check('HOD scope is team only', $hodScope['all'] === false && $hodScope['team'] === true);
check('HOD scope carries their employee record', $hodScope['employee_id'] === $hodEmployee);
check('HOD scope carries their department', $hodScope['department_id'] === $operations);

$hrScope = $leave->scopeFor($companyId, $hrUserId, true, true);
check('HR scope is company wide', $hrScope['all'] === true);

$hodList = $app->leave->requests->listForCompany($companyId, 'pending', $hodScope);
$ids = array_map(static fn (array $row): int => (int) $row['employee_id'], $hodList);
check('HOD sees their direct report', in_array($staffA, $ids, true));
check('HOD sees a cross-department direct report', in_array($reportAcross, $ids, true));
check('HOD does not see another department', !in_array($staffB, $ids, true));
check('HOD sees their own request in the list', in_array($hodEmployee, $ids, true));
check('HOD pending count skips their own request', $app->leave->requests->pendingCount($companyId, $hodScope) === 2, (string) $app->leave->requests->pendingCount($companyId, $hodScope));

$hrList = $app->leave->requests->listForCompany($companyId, 'pending', $hrScope);
check('HR sees every pending request', count($hrList) === 6);

$picker = $register->activeForSelectInScope($companyId, $hodScope);
$pickerIds = array_map(static fn (array $row): int => (int) $row['id'], $picker);
check('balance picker includes the team', in_array($staffA, $pickerIds, true));
check('balance picker excludes another department', !in_array($staffB, $pickerIds, true));

// ------------------------------------------------------------- decisions

check(
    'HOD approves a direct report',
    statusOf(static fn () => $leave->decide($companyId, $aRequest, 'approved', $hodUserId, '', false, true)) === 0,
);
check(
    'HOD approves a cross-department direct report',
    statusOf(static fn () => $leave->decide($companyId, $reportRequest, 'approved', $hodUserId, '', false, true)) === 0,
);
check(
    'HOD cannot decide another department',
    statusOf(static fn () => $leave->decide($companyId, $bRequest, 'approved', $hodUserId, '', false, true)) === 403,
);
check(
    'HOD cannot decide their own request',
    statusOf(static fn () => $leave->decide($companyId, $hodRequest, 'approved', $hodUserId, '', false, true)) === 403,
);
check(
    'HOD cannot decide their manager',
    statusOf(static fn () => $leave->decide($companyId, $gmRequest, 'approved', $hodUserId, '', false, true)) === 403,
);
check(
    'HR approves the HOD request',
    statusOf(static fn () => $leave->decide($companyId, $hodRequest, 'approved', $hrUserId, '', true, true)) === 0,
);
check(
    'HR cannot decide their own request',
    statusOf(static fn () => $leave->decide($companyId, $hrRequest, 'approved', $hrUserId, '', true, true)) === 403,
);
check(
    'a refused decision leaves the request pending',
    (string) $db->query('SELECT status FROM leave_requests WHERE id = ' . $bRequest)->fetchColumn() === 'pending',
);
check(
    'a team approver without a linked employee is refused',
    statusOf(static fn () => $leave->decide($companyId, $bRequest, 'approved', 1, '', false, true)) === 403,
);

// --------------------------------------------------------- employee checks

check(
    'an employee cannot report to themselves',
    statusOf(static fn () => $service->update($companyId, $staffA, ['manager_id' => $staffA], 1)) === 422,
);
check(
    'a reporting loop is refused',
    statusOf(static fn () => $service->update($companyId, $hodEmployee, ['manager_id' => $staffA], 1)) === 422,
);

$anotherCompany = $app->companyService->create('Other Co', 'other-co');
$outsider = $app->employees->service->create($anotherCompany, [
    'first_name' => 'Zed',
    'last_name' => 'Outsider',
    'start_date' => '2024-01-01',
], 1);
check(
    'a manager from another company is refused',
    statusOf(static fn () => $service->update($companyId, $staffA, ['manager_id' => $outsider], 1)) === 422,
);
check(
    'a login cannot be linked twice',
    statusOf(static fn () => $service->update($companyId, $staffB, ['user_id' => $hodUserId], 1)) === 422,
);
check(
    'a login outside the company is refused',
    statusOf(static fn () => $service->update($companyId, $staffB, ['user_id' => 99999], 1)) === 422,
);

// ------------------------------------------------------------ employee apply

$types = $app->leave->requests->types($companyId);
$maternityId = 0;

foreach ($types as $type) {
    if ($type['name'] === 'Maternity leave') {
        $maternityId = (int) $type['id'];
    }
}

$service->update($companyId, $staffA, ['gender' => 'female'], 1);
$service->update($companyId, $staffB, ['gender' => 'male'], 1);

$today = new DateTimeImmutable('today');
$start = $today->modify('+60 days');
$end = $start->modify('+2 days');
$submitted = $leave->submit(
    $companyId,
    $staffA,
    $annualId,
    $start->format('Y-m-d'),
    $end->format('Y-m-d'),
    'Family time',
    $hodUserId,
);
$submittedRow = $db->query('SELECT * FROM leave_requests WHERE id = ' . $submitted)->fetch();
check('an employee can submit leave', $submittedRow !== false && $submittedRow['status'] === 'pending');
check('submitted days are calendar days inclusive', (int) $submittedRow['days'] === 3);

$ownList = $app->leave->requests->listForEmployee($companyId, $staffA);
check('the submission appears in the employee history', in_array($submitted, array_map(static fn (array $row): int => (int) $row['id'], $ownList), true));

check(
    'an overlapping request is refused',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, $start->modify('+1 day')->format('Y-m-d'), $end->modify('+1 day')->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'leave cannot start in the past',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, $today->modify('-2 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'a reversed date range is refused',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, $end->format('Y-m-d'), $start->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'an impossible date is refused',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, '2027-02-31', '2027-03-01', '', $hodUserId)) === 422,
);
check(
    'a request above the available balance is refused',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, $start->modify('+10 days')->format('Y-m-d'), $start->modify('+40 days')->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'a gender-restricted type is refused for a male employee',
    statusOf(static fn () => $leave->submit($companyId, $staffB, $maternityId, $start->format('Y-m-d'), $end->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'a gender-restricted type is allowed for a female employee',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $maternityId, $start->modify('+80 days')->format('Y-m-d'), $start->modify('+82 days')->format('Y-m-d'), '', $hodUserId)) === 0,
);
check(
    'leave cannot be taken over an approved request',
    statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, '2026-12-02', '2026-12-04', '', $hodUserId)) === 422,
);
check(
    'an inactive leave type is refused',
    (static function () use ($app, $companyId, $annualId, $staffA, $start, $end, $leave, $hodUserId): bool {
        $app->leave->requests->setTypeActive($companyId, $annualId, false);

        try {
            return statusOf(static fn () => $leave->submit($companyId, $staffA, $annualId, $start->format('Y-m-d'), $end->format('Y-m-d'), '', $hodUserId)) === 422;
        } finally {
            $app->leave->requests->setTypeActive($companyId, $annualId, true);
        }
    })(),
);

echo "\n";
if ($failures !== 0) {
    echo $failures . ' of ' . $checks . " leave checks failed.\n";
    exit(1);
}

echo $checks . "/$checks checks passed.\nAll leave checks passed.\n";
