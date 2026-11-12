<?php

declare(strict_types=1);

use Mshahara\Payroll\App;

/**
 * Post every month of leave accrual and fixed grants through today.
 * Idempotent: each batch has a deterministic source key.
 *
 * Usage: php bin/backfill_leave_ledger.php [company_id]
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = App::boot($root);
$companyId = isset($argv[1]) ? (int) $argv[1] : 0;
$companies = $companyId > 0
    ? [$companyId]
    : array_map(static fn (array $row): int => (int) $row['id'], $app->db->query('SELECT id FROM companies ORDER BY id')->fetchAll());
$total = 0;

foreach ($companies as $id) {
    $created = $app->leave->accrual->postThrough($id, date('Y-m-d'));
    $app->leave->accrual->expire($id, date('Y-m-d'));
    $total += $created;
    echo 'Company #' . $id . ': ' . $created . " accrual/grant batch(es) created\n";
}

echo 'Leave accrual backfill complete: ' . $total . " batch(es). Re-running is safe.\n";
