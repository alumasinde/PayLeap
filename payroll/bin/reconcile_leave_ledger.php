<?php

declare(strict_types=1);

use Mshahara\Payroll\App;

/**
 * Reconcile legacy opening batches with the posted accrual history, and remove
 * untouched fixed grants from earlier leave years. This is a one-time repair
 * for companies created before monthly accrual was posted.
 *
 * Usage:
 *   php bin/reconcile_leave_ledger.php [company_id] --apply
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = App::boot($root);
$apply = in_array('--apply', $argv, true);
$companyIds = array_values(array_filter(
    array_map(static fn (string $arg): int => (int) $arg, array_slice($argv, 1)),
    static fn (int $id): bool => $id > 0,
));

if ($companyIds === []) {
    $companyIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $app->db->query('SELECT id FROM companies ORDER BY id')->fetchAll(),
    );
}

if (!$apply) {
    echo "Dry run. Re-run with --apply to reconcile openings and remove stale fixed grants.\n";
}

foreach ($companyIds as $companyId) {
    if (!$apply) {
        echo 'Company #' . $companyId . ": openings will be reconciled against posted credits.\n";
        continue;
    }

    $result = $app->leave->reconciliation->reconcileCompany($companyId, null);
    echo 'Company #' . $companyId . ': '
        . $result['historical_grants_removed'] . ' historical fixed grant(s) removed, '
        . $result['openings_adjusted'] . ' opening(s) reconciled, '
        . $result['openings_skipped'] . " opening(s) left alone\n";
}

echo $apply ? "Leave ledger reconciliation complete. Re-running is safe.\n" : "No changes written.\n";
