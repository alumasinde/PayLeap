<?php

declare(strict_types=1);

use Mshahara\Paye\Calculator;
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

function statusOf(callable $action): int
{
    try {
        $action();
    } catch (HttpException $error) {
        return $error->status;
    }

    return 0;
}

$companyId = $app->companyService->create('Payroll Co', 'payroll-co');

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

$owner = joinCompany($app, $companyId, 'owner@payroll.test', 'owner');
$finance = joinCompany($app, $companyId, 'finance@payroll.test', 'finance');
$hr = joinCompany($app, $companyId, 'hr@payroll.test', 'hr');

$ownerId = (int) $owner['id'];
$financeId = (int) $finance['id'];
$hrId = (int) $hr['id'];

$makeEmployee = static function (array $data) use ($app, $companyId, $ownerId): int {
    return $app->employees->service->create($companyId, $data + ['start_date' => '2025-01-01'], $ownerId);
};

$lowId = $makeEmployee([
    'first_name' => 'Amani',
    'last_name' => 'Otieno',
    'monthly_salary' => '50,000.00',
]);

$highId = $makeEmployee([
    'first_name' => 'Zawadi',
    'last_name' => 'Mwangi',
    'monthly_salary' => '100,000.00',
    'start_date' => '2025-06-01',
]);

$noSalaryId = $makeEmployee([
    'first_name' => 'Baraka',
    'last_name' => 'Juma',
]);

$futureId = $makeEmployee([
    'first_name' => 'Future',
    'last_name' => 'Hire',
    'monthly_salary' => '60000.00',
    'start_date' => '2026-04-01',
]);

$leaverId = $makeEmployee([
    'first_name' => 'Former',
    'last_name' => 'Staff',
    'monthly_salary' => '80000.00',
]);
$app->employees->service->archive($companyId, $leaverId, '2025-12-31', $ownerId);

// ------------------------------------------------------------------- a run

$runId = $app->payroll->service->prepare($companyId, '2026-03', '', $ownerId);
$run = $app->payroll->runs->find($companyId, $runId);

check('run starts as a draft', $run['status'] === 'draft');
check('label defaults to the month', $run['label'] === 'March 2026 payroll');
check('run keeps the rate version it used', $run['rate_version_id'] === 'ke-2026.02');
check('only active salaried staff are included', (int) $run['employee_count'] === 2);

$lines = $app->payroll->runs->lines($runId);
check('one line per included employee', count($lines) === 2);

$lineLow = null;
$lineHigh = null;

foreach ($lines as $line) {
    if ((int) $line['employee_id'] === $lowId) {
        $lineLow = $line;
    }

    if ((int) $line['employee_id'] === $highId) {
        $lineHigh = $line;
    }
}

check('a line is stored for each employee', $lineLow !== null && $lineHigh !== null);
check('no-salary staff are left out', !in_array($noSalaryId, array_map('intval', array_column($lines, 'employee_id')), true));
check('future starters are left out', !in_array($futureId, array_map('intval', array_column($lines, 'employee_id')), true));
check('leavers are left out', !in_array($leaverId, array_map('intval', array_column($lines, 'employee_id')), true));

// ------------------------------------------- figures match the shared engine

$rate = $app->payroll->rates->forDate('2026-03-01');
$calculator = new Calculator();
$expected = $calculator->payslip(50000.0, $rate);
$expectedCost = $calculator->employerCost(50000.0, $rate);

check('gross is the register salary', (int) $lineLow['gross_cents'] === 5000000);
check('PAYE matches the engine', (int) $lineLow['paye_cents'] === Money::fromShillings((float) $expected['paye']));
check('NSSF matches the engine', (int) $lineLow['nssf_cents'] === Money::fromShillings((float) $expected['nssf']['total']));
check('SHIF matches the engine', (int) $lineLow['shif_cents'] === Money::fromShillings((float) $expected['shif']));
check('housing levy matches the engine', (int) $lineLow['housing_levy_cents'] === Money::fromShillings((float) $expected['housing_levy']));
check('taxable pay matches the engine', (int) $lineLow['taxable_cents'] === Money::fromShillings((float) $expected['taxable_pay']));
check('net matches the engine', (int) $lineLow['net_cents'] === Money::fromShillings((float) $expected['net']));
check('deductions match the engine', (int) $lineLow['deductions_cents'] === Money::fromShillings((float) $expected['total_deductions']));
check('employer NSSF matches the engine', (int) $lineLow['employer_nssf_cents'] === Money::fromShillings((float) $expectedCost['nssf']));
check('employer cost matches the engine', (int) $lineLow['employer_cost_cents'] === Money::fromShillings((float) $expectedCost['total_cost']));

$breakdown = json_decode((string) $lineLow['breakdown'], true);
check(
    'the line keeps its full engine breakdown',
    is_array($breakdown) && ($breakdown['payslip']['rate_version'] ?? null) === 'ke-2026.02'
        && isset($breakdown['employer']['total_cost']),
);

$grossSum = array_sum(array_column($lines, 'gross_cents'));
$netSum = array_sum(array_column($lines, 'net_cents'));
$deductionSum = array_sum(array_column($lines, 'deductions_cents'));
$costSum = array_sum(array_column($lines, 'employer_cost_cents'));

check('run gross is the sum of its lines', (int) $run['gross_cents'] === $grossSum);
check('run net is the sum of its lines', (int) $run['net_cents'] === $netSum);
check('run deductions are the sum of its lines', (int) $run['deductions_cents'] === $deductionSum);
check('run employer cost is the sum of its lines', (int) $run['employer_cost_cents'] === $costSum);
check('net plus deductions equals gross', $netSum + $deductionSum === $grossSum);
check('net pay is below gross', $netSum < $grossSum);

// ------------------------------------------------------------ rate versions

check('older months use the older rates', $app->payroll->rates->forDate('2025-03-01')['id'] === 'ke-2025.02');
check('rates before the first version are unavailable', $app->payroll->rates->forDate('2024-12-01') === null);

// -------------------------------------------------------------------- guards

check('a second run for the same month is refused', statusOf(
    fn () => $app->payroll->service->prepare($companyId, '2026-03', '', $ownerId),
) === 409);

check('a malformed month is refused', statusOf(
    fn () => $app->payroll->service->prepare($companyId, '2026-13', '', $ownerId),
) === 422);

check('a month before any rates is refused', statusOf(
    fn () => $app->payroll->service->prepare($companyId, '2024-12', '', $ownerId),
) === 422);

check('an over-long label is refused', statusOf(
    fn () => $app->payroll->service->prepare($companyId, '2026-05', str_repeat('x', 121), $ownerId),
) === 422);

$emptyCompany = $app->companyService->create('Empty Co', 'empty-co');
check('a company with no salaried staff cannot prepare', statusOf(
    fn () => $app->payroll->service->prepare($emptyCompany, '2026-03', '', $ownerId),
) === 422);

// ---------------------------------------------------------------- approval

$secondRunId = $app->payroll->service->prepare($companyId, '2026-04', 'April 2026', $ownerId);

check('the preparer cannot approve their own run', statusOf(
    fn () => $app->payroll->service->approve($companyId, $secondRunId, $ownerId),
) === 409);

$app->payroll->service->approve($companyId, $secondRunId, $financeId);
$approved = $app->payroll->runs->find($companyId, $secondRunId);

check('a second approver can approve', $approved['status'] === 'approved');
check('the approver is recorded', (int) $approved['approved_by'] === $financeId);
check('the approval time is recorded', $approved['approved_at'] !== null);
check('an approved run cannot be approved again', statusOf(
    fn () => $app->payroll->service->approve($companyId, $secondRunId, $financeId),
) === 409);

$firstAgain = $app->payroll->runs->find($companyId, $runId);
check('approving one run leaves the other in draft', $firstAgain['status'] === 'draft');
check('the run list holds both runs', count($app->payroll->runs->listForCompany($companyId)) === 2);

// -------------------------------------------------------------------- scope

check('another company cannot open the run', $app->payroll->runs->find($emptyCompany, $runId) === null);
check('another company sees no runs', $app->payroll->runs->listForCompany($emptyCompany) === []);

$lineId = (int) $lineLow['id'];

check('a payslip line opens through its company', $app->payroll->runs->line($companyId, $runId, $lineId) !== null);
check('a payslip line cannot be read through another company', $app->payroll->runs->line($emptyCompany, $runId, $lineId) === null);
check('a payslip line cannot be read through another run', $app->payroll->runs->line($companyId, $secondRunId, $lineId) === null);

// -------------------------------------------------------------- permissions

check('finance may approve payroll', $app->permissionGate->allows($financeId, $companyId, 'payroll.approve'));
check('finance may prepare payroll', $app->permissionGate->allows($financeId, $companyId, 'payroll.run'));
check('hr may not prepare payroll', !$app->permissionGate->allows($hrId, $companyId, 'payroll.run'));

// ---------------------------------------------------------------- auditing

$actions = $db->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
check('audit records a prepared run', in_array('payroll.run.prepared', $actions, true));
check('audit records an approved run', in_array('payroll.run.approved', $actions, true));

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All payroll checks passed.\n";
