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

function statusOf(callable $action): int
{
    try {
        $action();
    } catch (HttpException $error) {
        return $error->status;
    }

    return 0;
}

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

$companyId = $app->companyService->create('Finance Co', 'finance-co');
$owner = joinCompany($app, $companyId, 'owner@finance.test', 'owner');
$finance = joinCompany($app, $companyId, 'finance@finance.test', 'finance');
$ownerId = (int) $owner['id'];
$financeId = (int) $finance['id'];

$employeeId = $app->employees->service->create($companyId, [
    'first_name' => 'Neema',
    'last_name' => 'Wanjiku',
    'employee_no' => 'FIN-001',
    'start_date' => '2025-01-01',
    'monthly_salary' => '50000',
    'bank_name' => 'Equity Bank',
    'bank_branch' => 'Westlands',
    'bank_account' => '0123456789',
    'mpesa_no' => '254700000001',
    'email' => 'neema@finance.test',
], $ownerId);

check('employee M-Pesa details are stored', ($app->employees->register->find($companyId, $employeeId)['mpesa_no'] ?? null) === '254700000001');

$houseId = $app->finance->service->savePayItem($companyId, null, [
    'code' => 'HOUSE',
    'name' => 'House allowance',
    'kind' => 'earning',
    'calc_type' => 'fixed',
    'taxable' => '1',
    'pensionable' => '1',
    'cash' => '1',
], $financeId);

$saccoId = $app->finance->service->savePayItem($companyId, null, [
    'code' => 'SACCO',
    'name' => 'SACCO contribution',
    'kind' => 'deduction',
    'calc_type' => 'fixed',
    'taxable' => '0',
    'cash' => '1',
], $financeId);

$app->finance->service->assignPayItem($companyId, [
    'employee_id' => (string) $employeeId,
    'pay_item_id' => (string) $houseId,
    'amount' => '10000',
    'start_date' => '2025-01-01',
], $financeId);
$app->finance->service->assignPayItem($companyId, [
    'employee_id' => (string) $employeeId,
    'pay_item_id' => (string) $saccoId,
    'amount' => '1000',
    'start_date' => '2025-01-01',
], $financeId);

check('pay items are stored against the company', count($app->finance->payItems->all($companyId)) === 2);
check('the employee has two recurring pay items', count($app->finance->payItems->forEmployee($companyId, $employeeId, '2026-03-01', '2026-03-31')) === 2);

$runId = $app->payroll->service->prepare($companyId, '2026-03', '', $ownerId);
$run = $app->payroll->runs->find($companyId, $runId);
$lines = $app->payroll->runs->lines($runId);
$line = $lines[0];

check('payroll includes the fixed allowance in gross', (int) $line['gross_cents'] === 6000000);
check('the SACCO deduction is included', (int) $line['other_deduction_cents'] === 100000);
check('line items are itemised', count($app->payroll->runs->lineItems($companyId, $runId)) >= 3);
check('run starts unpaid', $run['payment_status'] === 'unpaid');
check('run is a regular run', $run['run_type'] === 'regular');

$app->payroll->service->approve($companyId, $runId, $financeId);
$approved = $app->payroll->runs->find($companyId, $runId);
check('approval locks the run', $approved['status'] === 'approved' && $approved['locked_at'] !== null);

$batchId = $app->finance->service->createPayment($companyId, $runId, 'bank', $financeId);
$batch = $app->finance->payments->find($companyId, $batchId);
check('a bank payment batch is prepared', $batch !== null && $batch['status'] === 'prepared');
check('payment lines snapshot the net pay', (int) $app->finance->payments->lines($companyId, $batchId)[0]['amount_cents'] === (int) $line['net_cents']);

$app->finance->service->releasePayment($companyId, $batchId, $financeId);
$paidRun = $app->payroll->runs->find($companyId, $runId);
check('release marks the run paid', $paidRun['payment_status'] === 'paid' && $paidRun['paid_at'] !== null);

$app->employees->service->update($companyId, $employeeId, ['monthly_salary' => '55000'], $ownerId);
$adjustmentId = $app->payroll->service->prepareAdjustment($companyId, $runId, 'Salary correction', $ownerId);
$adjustment = $app->payroll->runs->find($companyId, $adjustmentId);
$adjustmentLine = $app->payroll->runs->lines($adjustmentId)[0];

check('a correction run is linked to its parent', $adjustment['run_type'] === 'adjustment' && (int) $adjustment['parent_run_id'] === $runId);
check('a correction carries only the difference', (int) $adjustmentLine['gross_cents'] === 500000);
check('a correction gets its own run number', (int) $adjustment['run_no'] === 2);
$app->payroll->service->approve($companyId, $adjustmentId, $financeId);

$returnId = $app->finance->service->generateReturn($companyId, $runId, 'p10', $financeId);
$return = $app->finance->returns->find($companyId, $returnId);
check('a P10 return is prepared from an approved run', $return !== null && $return['status'] === 'prepared');
check('the return has a snapshot payload', str_contains((string) $return['payload'], 'employee_no'));
$app->finance->service->markReturnFiled($companyId, $returnId, 'KRA-123', $financeId);
$filed = $app->finance->returns->find($companyId, $returnId);
check('a return records its filing reference', $filed['status'] === 'filed' && $filed['reference_no'] === 'KRA-123');

$report = $app->finance->service->report($companyId, 'gl-journal', '2026-03');
check('the GL journal balances', $report['totals']['debit_cents'] === $report['totals']['credit_cents']);
check('the payroll report has a net figure', $app->finance->service->report($companyId, 'payroll-summary', '2026-03')['totals']['net_cents'] > 0);

$loanId = $app->finance->service->createLoan($companyId, [
    'employee_id' => (string) $employeeId,
    'principal' => '12000',
    'installments' => '12',
    'rate_percent' => '0',
    'start_period' => '2026-05',
    'reference' => 'LOAN-TEST-1',
], $financeId);
check('a loan has a repayment schedule', count($app->finance->loans->schedule($companyId, $loanId)) === 12);

$expenseId = $app->finance->service->createExpense($companyId, [
    'employee_id' => (string) $employeeId,
    'category' => 'Travel',
    'period' => '2026-05',
    'amount' => '2500',
], $financeId);
$app->finance->service->decideExpense($companyId, $expenseId, 'approved', $financeId);

$commissionId = $app->finance->service->createCommission($companyId, [
    'employee_id' => (string) $employeeId,
    'period' => '2026-05',
    'base' => '100000',
    'amount' => '5000',
], $financeId);
check('a commission is queued for payroll', $commissionId > 0);

$advanceId = $app->finance->service->createAdvance($companyId, [
    'employee_id' => (string) $employeeId,
    'amount' => '3000',
    'installments' => '3',
    'start_period' => '2026-05',
    'reason' => 'Travel float',
], $financeId);
$app->finance->service->decideAdvance($companyId, $advanceId, 'approved', $financeId);
check('an approved advance becomes a loan', $app->finance->loans->findAdvance($companyId, $advanceId)['loan_id'] !== null);

$damageId = $app->finance->service->createDamage($companyId, [
    'employee_id' => (string) $employeeId,
    'description' => 'Damaged laptop charger',
    'amount' => '500',
], $financeId);
$app->finance->service->decideDamage($companyId, $damageId, 'approved', $financeId);

$finalId = $app->finance->service->createFinalDue($companyId, [
    'employee_id' => (string) $employeeId,
    'period' => '2026-06',
    'leave_payout' => '1000',
    'notice' => '0',
    'severance' => '0',
    'service_pay' => '0',
    'other' => '0',
], $financeId);
$app->finance->service->decideFinalDue($companyId, $finalId, 'approved', $financeId);

$mayRunId = $app->payroll->service->prepare($companyId, '2026-05', '', $ownerId);
$mayLines = $app->payroll->runs->lines($mayRunId);
$may = $mayLines[0];
check('loan recovery reaches payroll', (int) $may['loan_deduction_cents'] > 0);
check('advance recovery reaches payroll', (int) $may['advance_deduction_cents'] > 0);
check('commission is included in gross', (int) $may['commission_cents'] === 500000);
check('expense reimbursement is added to net', (int) $may['reimbursements_cents'] === 250000);
check('damage is recovered as a deduction', (int) $may['other_deduction_cents'] >= 50000);
check('the loan schedule is marked recovered', (int) $app->finance->loans->schedule($companyId, $loanId)[0]['paid_cents'] > 0);

$juneRunId = $app->payroll->service->prepare($companyId, '2026-06', '', $ownerId);
$june = $app->payroll->runs->lines($juneRunId)[0];
check('final dues reach payroll', (int) $june['final_dues_cents'] === 100000);

$rateCompanyId = $app->companyService->create('Rate Change Co', 'rate-change-co');
$rateOwner = joinCompany($app, $rateCompanyId, 'rateowner@finance.test', 'owner');
$rateOwnerId = (int) $rateOwner['id'];
$rateEmployeeId = $app->employees->service->create($rateCompanyId, [
    'first_name' => 'Rate',
    'last_name' => 'Change',
    'start_date' => '2026-01-01',
    'monthly_salary' => '31000',
], $rateOwnerId);
$app->employees->service->update($rateCompanyId, $rateEmployeeId, [
    'monthly_salary' => '62000',
    'salary_effective_date' => '2026-08-16',
], $rateOwnerId);
$rateRunId = $app->payroll->service->prepare($rateCompanyId, '2026-08', '', $rateOwnerId);
$rateLine = $app->payroll->runs->lines($rateRunId)[0];
check('a mid-month salary change is prorated', (int) $rateLine['gross_cents'] === 4700000, (string) $rateLine['gross_cents']);

$dashboard = $app->finance->service->dashboard($companyId);
check('finance dashboard exposes cash and exceptions', isset($dashboard['unpaid_count'], $dashboard['missing_bank'], $dashboard['statutory']['total']));

$slip = $app->finance->payslips->generate($companyId, $runId, (int) $line['id']);
check('a PDF payslip is generated', str_starts_with((string) file_get_contents($slip['path']), '%PDF-1.4'));
$delivery = $app->finance->payslips->email($companyId, $runId, (int) $line['id'], $financeId);
check('a payslip delivery is recorded', $delivery['status'] === 'sent' && count($app->finance->payslips->deliveries($companyId, $runId)) > 0);

$actions = $db->query('SELECT DISTINCT action FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
check('finance actions are audited', in_array('payment.released', $actions, true) && in_array('return.filed', $actions, true));

echo "\n" . ($checks - $failures) . "/{$checks} checks passed.\n";

if ($failures > 0) {
    echo "FAILED\n";
    exit(1);
}

echo "All finance checks passed.\n";
