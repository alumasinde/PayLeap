<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;

/**
 * One-time repair for companies that received ledger openings before monthly
 * accrual was posted. New balances are always posted by LeaveAccrualService;
 * this service only reconciles rows that already exist.
 *
 * An opening is a snapshot. Any posted credits inside its window are already
 * represented by that snapshot, so they are neutralised on the opening with an
 * append-only adjustment instead of deleting the opening or its allocations.
 */
final class LeaveReconciliationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly LeaveLedgerRepository $ledger,
    ) {
    }

    /**
     * @return array{historical_grants_removed:int,openings_adjusted:int,openings_skipped:int}
     */
    public function reconcileCompany(int $companyId, ?int $actorId = null): array
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $historical = $this->ledger->untouchedHistoricalFixedGrants(
                $companyId,
                date('Y') . '-01-01',
            );

            foreach ($historical as $batchId) {
                $this->ledger->deleteBatch($companyId, $batchId);
            }

            $adjusted = 0;
            $skipped = 0;

            foreach ($this->ledger->openingBatches($companyId) as $opening) {
                $batchId = (int) $opening['id'];

                if ($this->ledger->hasOpeningReconciliation($batchId)) {
                    $skipped++;
                    continue;
                }

                $posted = $this->ledger->postedCreditsThrough(
                    $companyId,
                    (int) $opening['employee_id'],
                    (int) $opening['leave_type_id'],
                    (string) $opening['period_end'],
                );

                if ($posted <= 0.0001) {
                    $skipped++;
                    continue;
                }

                $remaining = max(0.0, $this->ledger->batchBalance($batchId, (string) $opening['period_end']));
                $offset = round(min($remaining, $posted), 2);

                if ($offset <= 0.0001) {
                    $skipped++;
                    continue;
                }

                $this->ledger->addTransaction(
                    $companyId,
                    $batchId,
                    'adjustment',
                    -$offset,
                    (string) $opening['period_end'],
                    'opening_reconciliation',
                    $batchId,
                    null,
                    $actorId,
                    'Opening reconciled against posted history',
                );
                $adjusted++;
            }

            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $error;
        }

        return [
            'historical_grants_removed' => count($historical),
            'openings_adjusted' => $adjusted,
            'openings_skipped' => $skipped,
        ];
    }
}
