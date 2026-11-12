<?php

declare(strict_types=1);

use Mshahara\Payroll\App;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = App::boot($root);
$companyId = isset($argv[1]) ? (int) $argv[1] : 0;
$month = $argv[2] ?? date('Y-m');

if (preg_match('/^(\d{4})-(\d{2})$/', $month, $matches) !== 1) {
    fwrite(STDERR, "Usage: php bin/post_leave_accruals.php [company_id] [YYYY-MM]\n");
    exit(1);
}

$companies = $companyId > 0
    ? [$companyId]
    : array_map(static fn (array $row): int => (int) $row['id'], $app->db->query('SELECT id FROM companies ORDER BY id')->fetchAll());
$total = 0;
$created = 0;

foreach ($companies as $id) {
    $created = $app->leave->accrual->postMonth($id, (int) $matches[1], (int) $matches[2]);
    $total += $created;
    echo 'Company #' . $id . ': ' . $created . " accrual/grant batch(es) created\n";
}

echo 'Leave accrual posting complete: ' . $total . " batch(es). Re-running is safe.\n";
