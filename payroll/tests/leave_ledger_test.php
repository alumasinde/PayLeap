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

$companyId = $app->companyService->create('Ledger Test Co', 'ledger-test-co');
$employeeA = $app->employees->service->create($companyId, [
    'first_name' => 'Lena',
    'last_name' => 'Ledger',
    'start_date' => '2024-01-01',
], 1);
$employeeB = $app->employees->service->create($companyId, [
    'first_name' => 'Omar',
    'last_name' => 'Expired',
    'start_date' => '2024-01-01',
], 1);
$annual = $app->leave->requests->types($companyId)[0];
$annualId = (int) $annual['id'];
$ledger = $app->leave->ledger;

$batchOne = $ledger->createBatch(
    $companyId,
    $employeeA,
    $annualId,
    '2026-01-01',
    '2026-01-31',
    5.00,
    null,
    'accrual',
    'test:batch:one',
    null,
);
$ledger->addTransaction($companyId, $batchOne, 'accrual', 5.00, '2026-01-01', 'test', null, null, null);
$batchTwo = $ledger->createBatch(
    $companyId,
    $employeeA,
    $annualId,
    '2026-02-01',
    '2026-02-28',
    3.00,
    null,
    'accrual',
    'test:batch:two',
    null,
);
$ledger->addTransaction($companyId, $batchTwo, 'accrual', 3.00, '2026-02-01', 'test', null, null, null);

check('ledger balance sums accrual transactions', abs($ledger->balance($companyId, $employeeA, $annualId, '2026-03-01') - 8.0) < 0.001);
check('balance as of a date excludes later batches', abs($ledger->balance($companyId, $employeeA, $annualId, '2026-01-15') - 5.0) < 0.001);

$requestId = $app->leave->requests->createRequest($companyId, $employeeA, $annualId, [
    'start_date' => '2026-03-01',
    'end_date' => '2026-03-06',
    'days' => 6.0,
    'reason' => 'Ledger FIFO test',
]);

$db->beginTransaction();
$allocations = $ledger->allocateRequest($companyId, $requestId, $employeeA, $annualId, 6.0, '2026-03-01', null);
$db->commit();

check('FIFO uses the oldest batch first', (int) $allocations[0]['batch_id'] === $batchOne && abs($allocations[0]['days'] - 5.0) < 0.001);
check('FIFO spills into the next batch', count($allocations) === 2 && (int) $allocations[1]['batch_id'] === $batchTwo && abs($allocations[1]['days'] - 1.0) < 0.001);
check('balance drops by the request days', abs($ledger->balance($companyId, $employeeA, $annualId, '2026-03-06') - 2.0) < 0.001);
check('allocations record both batches', count($ledger->allocationsForRequest($companyId, $requestId)) === 2);

$db->beginTransaction();
$again = $ledger->allocateRequest($companyId, $requestId, $employeeA, $annualId, 6.0, '2026-03-01', null);
$db->commit();
check('allocating the same request twice is idempotent', count($again) === 2 && abs($ledger->balance($companyId, $employeeA, $annualId, '2026-03-06') - 2.0) < 0.001);

$db->beginTransaction();
$reversed = $ledger->reverseRequest($companyId, $requestId, '2026-03-07', null);
$db->commit();
check('cancellation reverses every used batch', $reversed === 2);
check('the first batch is restored exactly', abs($ledger->batchBalance($batchOne, '2026-03-07') - 5.0) < 0.001);
check('the second batch is restored exactly', abs($ledger->batchBalance($batchTwo, '2026-03-07') - 3.0) < 0.001);
check('the ledger balance is restored', abs($ledger->balance($companyId, $employeeA, $annualId, '2026-03-07') - 8.0) < 0.001);

$db->beginTransaction();
$reversedAgain = $ledger->reverseRequest($companyId, $requestId, '2026-03-07', null);
$db->commit();
check('reversing the same request twice is idempotent', $reversedAgain === 0);

$expiredBatch = $ledger->createBatch(
    $companyId,
    $employeeB,
    $annualId,
    '2025-01-01',
    '2025-12-31',
    4.00,
    '2026-01-31',
    'accrual',
    'test:expired',
    null,
);
$ledger->addTransaction($companyId, $expiredBatch, 'accrual', 4.00, '2025-01-01', 'test', null, null, null);
$expiredRequest = $app->leave->requests->createRequest($companyId, $employeeB, $annualId, [
    'start_date' => '2026-03-01',
    'end_date' => '2026-03-01',
    'days' => 1.0,
    'reason' => 'Expired batch test',
]);
$expiredThrew = false;

try {
    $db->beginTransaction();
    $ledger->allocateRequest($companyId, $expiredRequest, $employeeB, $annualId, 1.0, '2026-03-01', null);
    $db->commit();
} catch (RuntimeException) {
    $expiredThrew = true;

    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

check('an expired batch is excluded from FIFO', $expiredThrew);

echo "\n";
if ($failures !== 0) {
    echo $failures . ' of ' . $checks . " ledger checks failed.\n";
    exit(1);
}

echo $checks . "/$checks checks passed.\nAll leave ledger checks passed.\n";