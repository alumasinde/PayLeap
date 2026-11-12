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
$sickId = 0;

foreach ($types as $type) {
    if ($type['name'] === 'Maternity leave') {
        $maternityId = (int) $type['id'];
    }

    if ($type['name'] === 'Sick leave') {
        $sickId = (int) $type['id'];
    }
}

$service->update($companyId, $staffA, ['gender' => 'female'], 1);
$service->update($companyId, $staffB, ['gender' => 'male'], 1);

$today = new DateTimeImmutable('today');
$start = new DateTimeImmutable((int) $today->format('Y') . '-12-15');

if ($start <= $today) {
    $start = $today->modify('+1 day');
}

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
check('submitted days count working days only', (int) $submittedRow['days'] === 3);

$ownList = $app->leave->requests->listForEmployee($companyId, $staffA);
check('the submission appears in the employee history', in_array($submitted, array_map(static fn (array $row): int => (int) $row['id'], $ownList), true));
check('the history count matches the rows', $app->leave->requests->countForEmployee($companyId, $staffA) === 2);

$historyPageOne = $app->leave->requests->listForEmployee($companyId, $staffA, 1);
$historyPageTwo = $app->leave->requests->listForEmployee($companyId, $staffA, 1, 1);
check('the history can be paged one row at a time', count($historyPageOne) === 1 && count($historyPageTwo) === 1);
check('the history applies the offset', (int) $historyPageOne[0]['id'] !== (int) $historyPageTwo[0]['id']);

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

// ------------------------------------------------------------- accrual

$studyId = $leave->saveType($companyId, [
    'name' => 'Study leave',
    'days_per_year' => 12,
    'accrual_rule' => 'monthly',
    'accrual_start_months' => 0,
    'day_basis' => 'working',
    'paid' => 1,
    'gender_restriction' => 'any',
    'requires_document' => 1,
    'carry_over_max' => 0,
    'active' => 1,
], 1);
$studyType = $app->leave->requests->findType($companyId, $studyId);
check(
    'a leave type can be saved with monthly accrual',
    $studyType !== null
        && $studyType['accrual_rule'] === 'monthly'
        && (int) $studyType['accrual_start_months'] === 0
        && $studyType['day_basis'] === 'working',
);

$friday = $today->modify('next friday');
$maternityRequest = $leave->submit(
    $companyId,
    $staffA,
    $maternityId,
    $friday->format('Y-m-d'),
    $friday->modify('+3 days')->format('Y-m-d'),
    '',
    $hodUserId,
);
$maternityRow = $db->query('SELECT days FROM leave_requests WHERE id = ' . $maternityRequest)->fetch();
check(
    'calendar-day leave counts the weekend',
    $maternityRow !== false && (int) $maternityRow['days'] === 4,
);

$newHire = $service->create($companyId, [
    'first_name' => 'Njeri',
    'last_name' => 'New',
    'start_date' => $today->modify('first day of last month')->format('Y-m-d'),
], 1);
$newHireAnnual = null;
$newHireSick = null;

foreach ($app->leave->requests->balances($companyId, $newHire, (int) $today->format('Y')) as $balance) {
    if ((int) $balance['id'] === $annualId) {
        $newHireAnnual = $balance;
    }

    if ((int) $balance['id'] === $sickId) {
        $newHireSick = $balance;
    }
}

check(
    'a one-month joiner accrues one month, not the yearly entitlement',
    $newHireAnnual !== null
        && abs((float) $newHireAnnual['accrued'] - 1.75) < 0.001
        && (float) $newHireAnnual['days_per_year'] === 21.0,
    $newHireAnnual === null ? 'no annual balance' : (string) $newHireAnnual['accrued'],
);
check(
    'a request above the accrued balance is refused for a new joiner',
    statusOf(static fn () => $leave->submit($companyId, $newHire, $annualId, $today->format('Y-m-d'), $today->modify('+2 days')->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'a new joiner can book an accrued day',
    statusOf(static fn () => $leave->submit($companyId, $newHire, $annualId, $today->format('Y-m-d'), $today->format('Y-m-d'), '', $hodUserId)) === 0,
);
$saturday = $today->modify('next saturday');
check(
    'a weekend-only request is refused',
    statusOf(static fn () => $leave->submit($companyId, $newHire, $annualId, $saturday->format('Y-m-d'), $saturday->modify('+1 day')->format('Y-m-d'), '', $hodUserId)) === 422,
);
check(
    'sick leave waits for its qualifying period',
    $newHireSick !== null && abs((float) $newHireSick['accrued']) < 0.001,
    $newHireSick === null ? 'no sick balance' : (string) $newHireSick['accrued'],
);

$service->update($companyId, $newHire, [
    'start_date' => $today->modify('-3 months')->modify('first day of this month')->format('Y-m-d'),
], 1);
$sickAfterWait = null;

foreach ($app->leave->requests->balances($companyId, $newHire, (int) $today->format('Y')) as $balance) {
    if ((int) $balance['id'] === $sickId) {
        $sickAfterWait = $balance;
    }
}

check(
    'sick leave becomes available in full after two months',
    $sickAfterWait !== null && abs((float) $sickAfterWait['accrued'] - 14.0) < 0.001,
    $sickAfterWait === null ? 'no sick balance' : (string) $sickAfterWait['accrued'],
);

echo "\n";
if ($failures !== 0) {
    echo $failures . ' of ' . $checks . " leave checks failed.\n";
    exit(1);
}

echo $checks . "/$checks checks passed.\nAll leave checks passed.\n";
