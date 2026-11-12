<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Authz\Portal;
use Mshahara\Payroll\Http\Controllers\SelfServicePayslipController;
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

function statusOf(callable $action): int
{
    try {
        $action();
    } catch (HttpException $error) {
        return $error->status;
    }

    return 0;
}

$companyId = $app->companyService->create('Portal Co', 'portal-co');

/** @return array<string,mixed> */
function joinCompany(App $app, int $companyId, string $email, string $role): array
{
    $invite = $app->invitationService->create($companyId, $email, $role);

    return $app->invitationService->acceptNew(
        $app->invitationService->find($invite['token']),
        ucfirst(strstr($email, '@', true) ?: 'User'),
        'Tester',
        'correct horse battery',
    );
}

$owner = joinCompany($app, $companyId, 'owner@portal.test', 'owner');
$finance = joinCompany($app, $companyId, 'finance@portal.test', 'finance');
$staffA = joinCompany($app, $companyId, 'staff.a@portal.test', 'employee');
$staffB = joinCompany($app, $companyId, 'staff.b@portal.test', 'employee');
$hod = joinCompany($app, $companyId, 'hod@portal.test', 'hod');

$ownerId = (int) $owner['id'];
$financeId = (int) $finance['id'];
$staffAId = (int) $staffA['id'];
$staffBId = (int) $staffB['id'];
$hodId = (int) $hod['id'];

$departmentId = $app->employees->service->addDepartment($companyId, 'Operations', $ownerId);

$makeEmployee = static function (array $data) use ($app, $companyId, $ownerId): int {
    return $app->employees->service->create($companyId, $data + [
        'start_date' => '2025-01-01',
        'department_id' => 0,
    ], $ownerId);
};

$employeeA = $makeEmployee([
    'first_name' => 'Amina',
    'middle_name' => '',
    'last_name' => 'Njoroge',
    'user_id' => (string) $staffAId,
    'department_id' => (string) $departmentId,
    'monthly_salary' => '60000.00',
]);

$employeeB = $makeEmployee([
    'first_name' => 'Brian',
    'middle_name' => '',
    'last_name' => 'Kiptoo',
    'user_id' => (string) $staffBId,
    'monthly_salary' => '70000.00',
]);

$employeeHod = $makeEmployee([
    'first_name' => 'Hilda',
    'middle_name' => '',
    'last_name' => 'Owino',
    'user_id' => (string) $hodId,
    'department_id' => (string) $departmentId,
    'job_title' => 'Head of operations',
    'monthly_salary' => '90000.00',
]);

$app->employees->service->update($companyId, $employeeB, ['department_id' => (string) $departmentId], $ownerId);

// ------------------------------------------------------------------- a run

$runId = $app->payroll->service->prepare($companyId, '2026-03', '', $ownerId);
$app->payroll->service->approve($companyId, $runId, $financeId);

$lines = $app->payroll->runs->lines($runId);
$lineA = null;
$lineB = null;

foreach ($lines as $line) {
    if ((int) $line['employee_id'] === $employeeA) {
        $lineA = $line;
    }

    if ((int) $line['employee_id'] === $employeeB) {
        $lineB = $line;
    }
}

check('the approved run has a line per employee', $lineA !== null && $lineB !== null);

// ------------------------------------------------------------ ESS payslips

$payslipsA = $app->payroll->runs->payslipsForEmployee($companyId, $staffAId);
check('an employee sees only their own payslips', count($payslipsA) === 1 && (int) $payslipsA[0]['employee_id'] === $employeeA);
check('another employee payslip is not readable', $app->payroll->runs->payslipForEmployee($companyId, $staffAId, (int) $lineB['id']) === null);

$app->user = $staffA;
$app->company = ['company_id' => $companyId, 'company_name' => 'Portal Co'];
$app->portal = Portal::ESS;

$payslipController = $app->controller(SelfServicePayslipController::class);
check(
    'another employee payslip returns 404',
    statusOf(fn () => $payslipController->show(['line' => (string) $lineB['id']])) === 404,
);

// A draft run must stay invisible until it is approved.
$draftRunId = $app->payroll->service->prepare($companyId, '2026-04', '', $ownerId);
$draftLines = $app->payroll->runs->lines($draftRunId);
$draftLineA = null;

foreach ($draftLines as $line) {
    if ((int) $line['employee_id'] === $employeeA) {
        $draftLineA = $line;
    }
}

check('a draft payslip is hidden', $app->payroll->runs->payslipForEmployee($companyId, $staffAId, (int) $draftLineA['id']) === null);
check('drafts do not join the payslip list', count($app->payroll->runs->payslipsForEmployee($companyId, $staffAId)) === 1);

// --------------------------------------------------------------- portals

$checkFor = static fn (int $userId): callable => static function (string $permission) use ($app, $userId, $companyId): bool {
    return $app->permissionGate->allows($userId, $companyId, $permission);
};

$portalFor = static function (int $userId) use ($app, $checkFor, $companyId): Portal {
    return $app->portalResolver->resolve(
        $checkFor($userId),
        $app->employees->register->forUser($companyId, $userId) !== null,
    );
};

check('the owner lands in HR', $portalFor($ownerId) === Portal::HR);
check('finance lands in finance', $portalFor($financeId) === Portal::FINANCE);
check('an employee lands in ESS', $portalFor($staffAId) === Portal::ESS);
check('a HOD lands in ESS, not HR', $portalFor($hodId) === Portal::ESS);
check(
    'a HOD only has the ESS portal',
    $app->portalResolver->available($checkFor($hodId), true) === [Portal::ESS],
);
check(
    'the owner can enter all three portals',
    $app->portalResolver->available($checkFor($ownerId), true) === [Portal::HR, Portal::FINANCE, Portal::ESS],
);

// ------------------------------------------------- HOD team leave in ESS

$types = $app->leave->requests->types($companyId);
$annualTypeId = null;

foreach ($types as $type) {
    if ($type['name'] === 'Annual leave') {
        $annualTypeId = (int) $type['id'];
    }
}

$start = date('Y-m-d', strtotime('next monday'));
$requestId = $app->leave->service->submit(
    $companyId,
    $employeeA,
    (int) $annualTypeId,
    $start,
    $start,
    'Family event',
    $staffAId,
);

$view = App::boot($root);
$view->user = $hod;
$view->company = ['company_id' => $companyId, 'company_name' => 'Portal Co', 'role_names' => 'Head of department'];
$view->portal = Portal::ESS;

ob_start();
$view->controller(Mshahara\Payroll\Http\Controllers\SelfServiceController::class)->teamLeave();
$html = (string) ob_get_clean();

check('the ESS offers team leave to a HOD', str_contains($html, 'Team leave'));
check('the team leave page lists a departmental request', str_contains($html, 'Amina') && str_contains($html, 'Family event') === false);

$app->leave->service->decide($companyId, $requestId, 'approved', $hodId, '', false, true);
$decided = $app->leave->requests->find($companyId, $requestId);
check('a HOD approves departmental leave from ESS', $decided !== null && $decided['status'] === 'approved');

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All portal checks passed.\n";
