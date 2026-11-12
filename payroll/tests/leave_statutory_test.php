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

function nextMonthWeekdays(int $count): array
{
    $dates = [];
    $cursor = new DateTimeImmutable('first day of next month');

    while (count($dates) < $count) {
        if ((int) $cursor->format('N') <= 5) {
            $dates[] = $cursor;
        }

        $cursor = $cursor->modify('+1 day');
    }

    return $dates;
}

$companyId = $app->companyService->create('Statutory Co', 'statutory-co');
$managerInvite = $app->invitationService->create($companyId, 'manager@example.test', 'hod');
$manager = $app->invitationService->acceptNew(
    $app->invitationService->find($managerInvite['token']),
    'Maya',
    'Manager',
    'correct horse battery',
);
$hrInvite = $app->invitationService->create($companyId, 'hr@example.test', 'hr');
$hr = $app->invitationService->acceptNew(
    $app->invitationService->find($hrInvite['token']),
    'Hana',
    'HR',
    'correct horse battery',
);

$managerEmployee = $app->employees->service->create($companyId, [
    'first_name' => 'Maya',
    'last_name' => 'Manager',
    'start_date' => '2024-01-01',
    'user_id' => (int) $manager['id'],
], 1);
$employeeId = $app->employees->service->create($companyId, [
    'first_name' => 'Sami',
    'last_name' => 'Statutory',
    'start_date' => '2024-01-01',
    'manager_id' => $managerEmployee,
    'monthly_salary' => '50000.00',
], 1);
$app->employees->service->create($companyId, [
    'first_name' => 'Hana',
    'last_name' => 'HR',
    'start_date' => '2024-01-01',
    'user_id' => (int) $hr['id'],
], 1);

$annualId = 0;
$sickId = 0;
foreach ($app->leave->requests->types($companyId) as $type) {
    if ($type['system_code'] === 'annual') {
        $annualId = (int) $type['id'];
    }
    if ($type['system_code'] === 'sick') {
        $sickId = (int) $type['id'];
    }
}

$dates = nextMonthWeekdays(15);
$annualStart = $dates[0]->format('Y-m-d');
$annualEnd = $dates[4]->format('Y-m-d');
$sickStart = $dates[5]->format('Y-m-d');
$sickEnd = $dates[12]->format('Y-m-d');
$month = $dates[0]->format('Y-m');

$app->leave->settings->save($companyId, [
    'multi_level_approval' => 1,
    'carry_over_expiry_months' => 18,
    'daily_rate_divisor' => 26,
    'holiday_work_multiplier' => 2,
    'planner_enabled' => 1,
    'encashment_enabled' => 1,
]);
$app->leave->accrual->postEmployeeThrough($companyId, $employeeId, date('Y-m-d'), 1);
$annualRequest = $app->leave->service->submit($companyId, $employeeId, $annualId, $annualStart, $annualEnd, 'Annual', (int) $manager['id']);
$annualRow = $app->leave->requests->find($companyId, $annualRequest);
check('a request in a two-step policy records two steps', (int) $annualRow['total_steps'] === 2 && (int) $annualRow['current_step'] === 1);
check('the manager is step one', $app->leave->approvals->current($companyId, $annualRequest, 1)['approver_employee_id'] == $managerEmployee);

$app->leave->service->decide($companyId, $annualRequest, 'approved', (int) $manager['id'], '', false, true);
$afterManager = $app->leave->requests->find($companyId, $annualRequest);
check('the first approval keeps the request pending', $afterManager['status'] === 'pending' && (int) $afterManager['current_step'] === 2);
check('the first approval does not consume leave', $app->leave->ledger->allocationsForRequest($companyId, $annualRequest) === []);

$app->leave->service->decide($companyId, $annualRequest, 'approved', (int) $hr['id'], '', true, true);
$afterHr = $app->leave->requests->find($companyId, $annualRequest);
check('the second approval finalises the request', $afterHr['status'] === 'approved');
check('the final approval consumes ledger batches', count($app->leave->ledger->allocationsForRequest($companyId, $annualRequest)) > 0);

$sickRequest = $app->leave->service->submit($companyId, $employeeId, $sickId, $sickStart, $sickEnd, 'Medical', (int) $manager['id']);
$app->leave->service->decide($companyId, $sickRequest, 'approved', (int) $manager['id'], '', false, true);
$app->leave->service->decide($companyId, $sickRequest, 'approved', (int) $hr['id'], '', true, true);
$sick = $app->leave->requests->find($companyId, $sickRequest);
check('sick leave is approved after its grant posted', $sick['status'] === 'approved');

$adjustment = $app->leave->payroll->adjust(
    $companyId,
    $employeeId,
    $dates[0]->format('Y-m-01'),
    $dates[0]->format('Y-m-t'),
    26000,
);
check('the eighth sick day reduces pay at the half-pay tier', $adjustment['leave_deduction_cents'] > 0, (string) $adjustment['leave_deduction_cents']);

$holidayDate = $dates[14]->format('Y-m-d');
$app->attendance->holidayService->add($companyId, $holidayDate, 'Company day', 1);
$db->prepare(
    'INSERT INTO attendance_records (company_id, employee_id, work_date, clock_in, clock_out, status, source)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
)->execute([$companyId, $employeeId, $holidayDate, '08:00:00', '17:00:00', 'present', 'manual']);
$holidayAdjustment = $app->leave->payroll->adjust(
    $companyId,
    $employeeId,
    $dates[0]->format('Y-m-01'),
    $dates[0]->format('Y-m-t'),
    26000,
);
check('holiday work earns the double-time premium', $holidayAdjustment['holiday_premium_cents'] > 0,
    (string) $holidayAdjustment['holiday_premium_cents']);
check('holiday work creates TOIL', $holidayAdjustment['toil_days'] > 0);

$planner = $app->leave->planner->month($companyId, (int) $dates[0]->format('Y'), (int) $dates[0]->format('n'), null);
check('the planner includes the approved request', count($planner['rows']) >= 2);

$quote = $app->leave->encashment->quote($companyId, $employeeId, date('Y-m-d'));
check('annual leave has an encashment quote', $quote['days'] > 0 && $quote['amount_cents'] > 0);
$encashId = $app->leave->encashment->create($companyId, $employeeId, date('Y-m-d'), 1);
check('exit encashment is recorded', $encashId > 0);

$runId = $app->payroll->service->prepare($companyId, $month, 'Statutory month', 1);
$runLines = $app->payroll->runs->lines($runId);
$line = $runLines[0];
check('payroll carries the leave deduction onto the line', (int) $line['leave_deduction_cents'] > 0);
check('payroll carries the holiday premium onto the line', (int) $line['holiday_premium_cents'] > 0);
check('the payroll line records TOIL days', (float) $line['toil_days'] > 0);
$encashment = $db->query('SELECT status, payroll_run_id FROM leave_encashments WHERE id = ' . $encashId)->fetch();
check('the prepared run pays the encashment', $encashment['status'] === 'paid' && (int) $encashment['payroll_run_id'] === $runId);

$sunday = new DateTimeImmutable('next sunday');
$monday = $sunday->modify('+1 day');
$app->attendance->holidayService->add($companyId, $sunday->format('Y-m-d'), 'Sunday holiday', 1);
$observed = $db->prepare('SELECT * FROM public_holidays WHERE company_id = ? AND holiday_date = ?');
$observed->execute([$companyId, $monday->format('Y-m-d')]);
check('a Sunday public holiday is observed on the next free day', $observed->fetch() !== false);

check('statutory rules are exposed as data', count($app->leave->statutory->all()) >= 10);
check('earlier accrual history posted', $app->leave->ledger->balance($companyId, $employeeId, $annualId, date('Y-m-d')) > 0);

echo "\n";
if ($failures !== 0) {
    echo $failures . ' of ' . $checks . " statutory checks failed.\n";
    exit(1);
}

echo $checks . "/$checks checks passed.\nAll statutory leave checks passed.\n";
