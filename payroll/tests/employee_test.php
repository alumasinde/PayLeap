<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Migrator;
use Mshahara\Payroll\Support\Money;

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
$invite = $app->invitationService->create($companyId, 'hr@example.test', 'hr');
$hr = $app->invitationService->acceptNew(
    $app->invitationService->find($invite['token']),
    'Hilda',
    'Rey',
    'correct horse battery',
);
$hrId = (int) $hr['id'];

$service = $app->employees->service;
$register = $app->employees->register;

// ------------------------------------------------------------------ money

check('money parses shillings into cents', Money::toCents('45,000.50') === 4500050);
check('money rejects too many decimals', Money::toCents('1.234') === null);
check('money rejects text', Money::toCents('forty thousand') === null);
check('money formats cents', Money::format(4500050) === '45,000.50');

// ------------------------------------------------------------------ people

$wanjikuId = $service->create($companyId, [
    'first_name' => 'Wanjiku',
    'last_name' => 'Kamau',
    'middle_name' => 'Njeri',
    'email' => 'Wanjiku@Example.test',
    'phone' => '0712 345 678',
    'national_id' => '1234 5678',
    'kra_pin' => 'a012345678z',
    'nssf_number' => '1234567',
    'shif_number' => '7654321',
    'job_title' => 'Accountant',
    'employment_type' => 'permanent',
    'monthly_salary' => '85,000.50',
    'start_date' => '2024-02-01',
], $hrId);

$wanjiku = $register->find($companyId, $wanjikuId);

check('employee number is generated', $wanjiku['employee_no'] === 'EMP-0001');
check('salary is stored as cents', (int) $wanjiku['monthly_salary_cents'] === 8500050);
check('kra pin is normalised', $wanjiku['kra_pin'] === 'A012345678Z');
check('email is lowercased', $wanjiku['email'] === 'wanjiku@example.test');
check('national id loses spaces', $wanjiku['national_id'] === '12345678');
check('employee starts active', $wanjiku['status'] === 'active');

$secondId = $service->create($companyId, [
    'first_name' => 'Otieno',
    'last_name' => 'Odhiambo',
    'employment_type' => 'casual',
    'start_date' => '2025-05-10',
], $hrId);

check('numbers continue per company', $register->find($companyId, $secondId)['employee_no'] === 'EMP-0002');

$withNumber = $service->create($companyId, [
    'employee_no' => 'ACC-100',
    'first_name' => 'Amina',
    'last_name' => 'Hassan',
    'start_date' => '2023-01-15',
], $hrId);

check('a supplied employee number is kept', $register->find($companyId, $withNumber)['employee_no'] === 'ACC-100');

check('duplicate employee number is rejected', statusOf(fn () => $service->create($companyId, [
    'employee_no' => 'ACC-100',
    'first_name' => 'Copy',
    'last_name' => 'Cat',
    'start_date' => '2024-01-01',
], $hrId)) === 422);

check('bad kra pin is rejected', statusOf(fn () => $service->create($companyId, [
    'first_name' => 'Bad',
    'last_name' => 'Pin',
    'kra_pin' => '12345',
    'start_date' => '2024-01-01',
], $hrId)) === 422);

check('bad email is rejected', statusOf(fn () => $service->create($companyId, [
    'first_name' => 'Bad',
    'last_name' => 'Email',
    'email' => 'not-an-email',
    'start_date' => '2024-01-01',
], $hrId)) === 422);

check('bad salary is rejected', statusOf(fn () => $service->create($companyId, [
    'first_name' => 'Bad',
    'last_name' => 'Salary',
    'monthly_salary' => 'lots',
    'start_date' => '2024-01-01',
], $hrId)) === 422);

check('missing name is rejected', statusOf(fn () => $service->create($companyId, [
    'first_name' => '',
    'last_name' => 'Nameless',
    'start_date' => '2024-01-01',
], $hrId)) === 422);

check('exit before start is rejected', statusOf(fn () => $service->create($companyId, [
    'first_name' => 'Time',
    'last_name' => 'Travel',
    'start_date' => '2024-01-01',
    'exit_date' => '2023-01-01',
], $hrId)) === 422);

check('unknown department is rejected', statusOf(fn () => $service->create($companyId, [
    'first_name' => 'No',
    'last_name' => 'Department',
    'department_id' => '999999',
    'start_date' => '2024-01-01',
], $hrId)) === 422);

// ------------------------------------------------------------- departments

$financeId = $service->addDepartment($companyId, 'Finance', $hrId);
check('a department is created', $app->employees->departments->find($companyId, $financeId)['name'] === 'Finance');
check('duplicate department is rejected', statusOf(fn () => $service->addDepartment($companyId, 'Finance', $hrId)) === 422);

$service->update($companyId, $wanjikuId, ['department_id' => (string) $financeId], $hrId);
check('employee joins a department', $register->find($companyId, $wanjikuId)['department_name'] === 'Finance');
check('the register can isolate people without a manager',
    $register->count($companyId, ['status' => 'active', 'attention' => 'no_manager']) === 3);
check('the register can isolate people without a department',
    $register->count($companyId, ['status' => 'active', 'attention' => 'no_department']) === 2);

// ------------------------------------------------------------------ search

$found = $register->search($companyId, ['status' => 'active', 'search' => 'wanjiku'], 20, 0);
check('search finds by name', count($found) === 1 && (int) $found[0]['id'] === $wanjikuId);

$found = $register->search($companyId, ['status' => 'all', 'search' => 'A012345678Z'], 20, 0);
check('search finds by kra pin', count($found) === 1);

$found = $register->search($companyId, ['status' => 'all', 'search' => 'ACC-100'], 20, 0);
check('search finds by employee number', count($found) === 1);

$found = $register->search($companyId, ['status' => 'active', 'department_id' => $financeId], 20, 0);
check('department filter works', count($found) === 1);

// ------------------------------------------------------------- partial save

$before = $register->find($companyId, $wanjikuId);
$service->update($companyId, $wanjikuId, ['job_title' => 'Senior accountant'], $hrId);
$after = $register->find($companyId, $wanjikuId);

check('partial update changes the field', $after['job_title'] === 'Senior accountant');
check('partial update keeps the salary', (int) $after['monthly_salary_cents'] === (int) $before['monthly_salary_cents']);
check('partial update keeps bank details', $after['bank_name'] === $before['bank_name']);

$service->update($companyId, $wanjikuId, ['monthly_salary' => ''], $hrId);
check('clearing the salary stores null', $register->find($companyId, $wanjikuId)['monthly_salary_cents'] === null);

check('update rejects a duplicate number', statusOf(fn () => $service->update(
    $companyId,
    $wanjikuId,
    ['employee_no' => 'ACC-100'],
    $hrId,
)) === 422);

check('update rejects exit before start', statusOf(fn () => $service->update(
    $companyId,
    $wanjikuId,
    ['exit_date' => '2020-01-01'],
    $hrId,
)) === 422);

// ------------------------------------------------------------------ archive

$service->archive($companyId, $wanjikuId, '2025-12-31', $hrId);
$archived = $register->find($companyId, $wanjikuId);

check('archiving sets the status', $archived['status'] === 'exited');
check('archiving records the exit date', $archived['exit_date'] === '2025-12-31');
check('archived people leave the active list', $register->count($companyId, ['status' => 'active']) === 2);
check('archived people appear when asked', $register->count($companyId, ['status' => 'exited']) === 1);
check('archiving twice is refused', statusOf(fn () => $service->archive($companyId, $wanjikuId, '', $hrId)) === 409);

$service->reinstate($companyId, $wanjikuId, $hrId);
$reinstated = $register->find($companyId, $wanjikuId);
check('reinstating clears the exit date', $reinstated['status'] === 'active' && $reinstated['exit_date'] === null);
check('reinstating an active person is refused', statusOf(fn () => $service->reinstate($companyId, $wanjikuId, $hrId)) === 409);

// ------------------------------------------------------------- cost centers

$changed = $service->bulkCostCenter($companyId, [$wanjikuId, $secondId], 'Operations', $hrId);
check('bulk cost-center update changes selected employees', $changed === 2);
check('bulk cost-center is stored on each employee',
    $register->find($companyId, $wanjikuId)['cost_center'] === 'Operations'
    && $register->find($companyId, $secondId)['cost_center'] === 'Operations');
check('cost centers are listed for the picker', in_array('Operations', $register->costCenters($companyId), true));
check('bulk cost-center requires a selection', statusOf(fn () => $service->bulkCostCenter($companyId, [], 'Operations', $hrId)) === 422);

// ------------------------------------------------------------------- scope

$otherCompany = $app->companyService->create('Other Co', 'other-co');
check('another company cannot read the record', $register->find($otherCompany, $wanjikuId) === null);
check('another company sees an empty register', $register->count($otherCompany, ['status' => 'all']) === 0);

$app->employees->departments->create($otherCompany, 'Finance');
check('departments are scoped by company', count($app->employees->departments->all($otherCompany)) === 1);

// --------------------------------------------------------------- summaries

$summary = $register->summary($companyId, true);
check('summary counts active people', $summary['active'] === 3 && $summary['total'] === 3);
check('summary adds the monthly salary bill', $summary['monthly_cents'] === 0);

// ---------------------------------------------------------------- auditing

$actions = $db->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
check('audit records employee creation', in_array('employee.created', $actions, true));
check('audit records employee updates', in_array('employee.updated', $actions, true));
check('audit records archiving', in_array('employee.archived', $actions, true));
check('audit records departments', in_array('department.created', $actions, true));
check('audit records bulk cost centers', in_array('employee.cost_center_bulk', $actions, true));

// --------------------------------------------------------------- permissions

check('hr may edit employees', $app->permissionGate->allows($hrId, $companyId, 'employees.edit'));
check('hr may see salaries', $app->permissionGate->allows($hrId, $companyId, 'employees.salary.view'));
check('hr may not see bank details', !$app->permissionGate->allows($hrId, $companyId, 'employees.bank.view'));

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All employee checks passed.\n";
