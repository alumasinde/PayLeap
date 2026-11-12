<?php

declare(strict_types=1);

/**
 * Idempotent opening-balance backfill for the leave ledger.
 * Run after 0014_leave_ledger: php bin/backfill_leave_ledger.php [company_id]
 */

use Mshahara\Payroll\App;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = App::boot($root);
$companyId = isset($argv[1]) ? (int) $argv[1] : null;

if ($companyId !== null) {
    $companies = [$companyId];
} else {
    $companies = array_map(
        static fn (array $row): int => (int) $row['id'],
        $app->db->query('SELECT id FROM companies ORDER BY id')->fetchAll(),
    );
}

$total = 0;

foreach ($companies as $id) {
    $created = $app->leave->balance->backfillCompany($id);
    $total += $created;
    echo "Company #{$id}: {$created} opening batch(es) created\n";
}

echo "Backfill complete: {$total} opening batch(es). Re-running is safe.\n";