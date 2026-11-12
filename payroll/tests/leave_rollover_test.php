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

$companyId = $app->companyService->create('Rollover Co', 'rollover-co');
$employeeId = $app->employees->service->create($companyId, [
    'first_name' => 'Rita',
    'last_name' => 'Rollover',
    'start_date' => '2026-01-01',
], 1);
$annual = null;
$sick = null;

foreach ($app->leave->requests->types($companyId) as $type) {
    if ($type['system_code'] === 'annual') {
        $annual = $type;
    }

    if ($type['system_code'] === 'sick') {
        $sick = $type;
    }
}

check('annual leave is configured from the statutory defaults', $annual !== null);
check('sick leave is configured from the statutory defaults', $sick !== null);

$app->leave->accrual->postMonth($companyId, 2026, 1, null);
check('January is not yet a completed month of service', $app->leave->balance->balance($companyId, (int) $employeeId, (int) $annual['id'], '2026-01-31') === 0.0);

$app->leave->accrual->postMonth($companyId, 2026, 2, null);
$february = $app->leave->balance->balance($companyId, (int) $employeeId, (int) $annual['id'], '2026-02-28');
check('February posts one monthly accrual', abs($february - 1.75) < 0.001, (string) $february);
check('the accrual is a ledger transaction', (int) $db->query(
    'SELECT COUNT(*) FROM leave_transactions WHERE ref_type = \'leave_accrual\'',
)->fetchColumn() === 1);

$batchCount = (int) $db->query('SELECT COUNT(*) FROM leave_batches')->fetchColumn();
$transactionCount = (int) $db->query('SELECT COUNT(*) FROM leave_transactions')->fetchColumn();
$rows = $app->leave->balance->balances($companyId, (int) $employeeId, 2026, '2026-02-28');
$annualRow = null;

foreach ($rows as $row) {
    if ((int) $row['id'] === (int) $annual['id']) {
        $annualRow = $row;
    }
}

check('the balance page reads the ledger', $annualRow !== null && abs((float) $annualRow['available'] - 1.75) < 0.001);
check('viewing balances writes no batches', (int) $db->query('SELECT COUNT(*) FROM leave_batches')->fetchColumn() === $batchCount);
check('viewing balances writes no transactions', (int) $db->query('SELECT COUNT(*) FROM leave_transactions')->fetchColumn() === $transactionCount);

$app->leave->accrual->postMonth($companyId, 2026, 3, null);
$march = $app->leave->balance->balance($companyId, (int) $employeeId, (int) $annual['id'], '2026-03-31');
check('March rolls the monthly balance forward', abs($march - 3.50) < 0.001, (string) $march);
check('the third month is a second accrual transaction', (int) $db->query(
    'SELECT COUNT(*) FROM leave_transactions WHERE ref_type = \'leave_accrual\'',
)->fetchColumn() === 2);

$app->leave->accrual->postMonth($companyId, 2026, 3, null);
check('posting the same month twice is idempotent', abs($app->leave->balance->balance($companyId, (int) $employeeId, (int) $annual['id'], '2026-03-31') - 3.50) < 0.001);

$app->leave->accrual->postMonth($companyId, 2026, 3, null);
$sickDays = $app->leave->balance->balance($companyId, (int) $employeeId, (int) $sick['id'], '2026-03-31');
check('the fixed sick grant posts after its waiting period', abs($sickDays - 14.0) < 0.001, (string) $sickDays);
$app->leave->accrual->postMonth($companyId, 2026, 4, null);
check('the fixed grant is not posted twice', abs($app->leave->balance->balance($companyId, (int) $employeeId, (int) $sick['id'], '2026-04-30') - 14.0) < 0.001);

$expired = $app->leave->accrual->expire($companyId, '2028-07-01');
check('expired carry-over is flagged for HR by default', $expired > 0);
check('flagged carry-over is not silently forfeited', (int) $db->query(
    'SELECT COUNT(*) FROM leave_exceptions WHERE kind = \'expiry\'',
)->fetchColumn() > 0);
check('no expiry transaction is written while forfeiture is off', (int) $db->query(
    'SELECT COUNT(*) FROM leave_transactions WHERE type = \'expiry\'',
)->fetchColumn() === 0);

// A legacy opening covers its month, but must not stop the next month from
// posting. This is the reconciliation boundary between old snapshots and the
// new accrual job.
$legacyCompany = $app->companyService->create('Legacy Opening Co', 'legacy-opening-co');
$legacyEmployee = $app->employees->service->create($legacyCompany, [
    'first_name' => 'Lydia',
    'last_name' => 'Legacy',
    'start_date' => '2025-12-01',
], 1);
$legacyAnnual = null;

foreach ($app->leave->requests->types($legacyCompany) as $type) {
    if ($type['system_code'] === 'annual') {
        $legacyAnnual = $type;
    }
}

$legacyBatch = $app->leave->ledger->createBatch(
    $legacyCompany,
    $legacyEmployee,
    (int) $legacyAnnual['id'],
    '2026-01-01',
    '2026-01-31',
    1.75,
    null,
    'opening',
    'opening:' . $legacyCompany . ':' . $legacyEmployee . ':' . $legacyAnnual['id'] . ':2026',
    null,
);
$app->leave->ledger->addTransaction(
    $legacyCompany,
    $legacyBatch,
    'accrual',
    1.75,
    '2026-01-31',
    'opening_balance',
    null,
    null,
    null,
    'Legacy opening',
);

$app->leave->accrual->postMonth($legacyCompany, 2026, 1, null);
check(
    'a legacy opening covers its own month without a duplicate accrual',
    (int) $db->query(
        'SELECT COUNT(*) FROM leave_batches
         WHERE company_id = ' . $legacyCompany . " AND source_key LIKE 'accrual:%:2026-01'",
    )->fetchColumn() === 0,
);
check(
    'the covered month still reads the opening balance',
    abs($app->leave->balance->balance($legacyCompany, (int) $legacyEmployee, (int) $legacyAnnual['id'], '2026-01-31') - 1.75) < 0.001,
);

$app->leave->accrual->postMonth($legacyCompany, 2026, 2, null);
check(
    'the next month posts a new accrual after a legacy opening',
    (int) $db->query(
        'SELECT COUNT(*) FROM leave_batches
         WHERE company_id = ' . $legacyCompany . " AND source_key LIKE 'accrual:%:2026-02'",
    )->fetchColumn() === 1,
);
check(
    'the next month grows the balance from transactions',
    abs($app->leave->balance->balance($legacyCompany, (int) $legacyEmployee, (int) $legacyAnnual['id'], '2026-02-28') - 3.50) < 0.001,
);

echo "\n";
if ($failures !== 0) {
    echo $failures . ' of ' . $checks . " rollover checks failed.\n";
    exit(1);
}

echo $checks . "/$checks checks passed.\nAll leave rollover checks passed.\n";
