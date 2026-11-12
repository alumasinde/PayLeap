<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;
use RuntimeException;

/**
 * Append-only leave ledger. Batches hold grants; transactions are the only
 * source of a balance. Request allocations record precisely which batches an
 * approval consumed, so a cancellation can reverse those batches.
 */
final class LeaveLedgerRepository
{
    private const TYPES = ['accrual', 'usage', 'expiry', 'adjustment', 'reversal'];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function batchBySourceKey(int $companyId, string $sourceKey): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_batches WHERE company_id = ? AND source_key = ? LIMIT 1',
        );
        $statement->execute([$companyId, $sourceKey]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function createBatch(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $periodStart,
        string $periodEnd,
        float $daysEarned,
        ?string $expiryDate,
        string $source,
        string $sourceKey,
        ?int $actorId,
    ): int {
        $this->db
            ->prepare(
                'INSERT INTO leave_batches
                    (company_id, employee_id, leave_type_id, period_start, period_end,
                     days_earned, expiry_date, source, source_key, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )
            ->execute([
                $companyId,
                $employeeId,
                $typeId,
                $periodStart,
                $periodEnd,
                $daysEarned,
                $expiryDate,
                $source,
                $sourceKey,
                $actorId,
            ]);

        return (int) $this->db->lastInsertId();
    }

    public function addTransaction(
        int $companyId,
        int $batchId,
        string $type,
        float $days,
        string $effectiveDate,
        ?string $refType,
        ?int $refId,
        ?int $reversalOfId,
        ?int $actorId,
        ?string $note = null,
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Unknown leave transaction type.');
        }

        $this->db
            ->prepare(
                'INSERT INTO leave_transactions
                    (company_id, batch_id, type, days, effective_date, ref_type, ref_id,
                     reversal_of_id, created_by, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )
            ->execute([
                $companyId,
                $batchId,
                $type,
                round($days, 2),
                $effectiveDate,
                $refType,
                $refId,
                $reversalOfId,
                $actorId,
                $note,
            ]);

        return (int) $this->db->lastInsertId();
    }

    /** Remaining days in one batch at a point in time. */
    public function batchBalance(int $batchId, string $asOf): float
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(days), 0) FROM leave_transactions
             WHERE batch_id = ? AND effective_date <= ?',
        );
        $statement->execute([$batchId, $asOf]);

        return round((float) $statement->fetchColumn(), 2);
    }

    /** Ledger balance for one employee and type, from transactions only. */
    public function balance(int $companyId, int $employeeId, int $typeId, string $asOf): float
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND t.effective_date <= ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, $asOf]);

        return round((float) $statement->fetchColumn(), 2);
    }

    public function balanceBetween(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $from,
        string $to,
    ): float {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND t.effective_date BETWEEN ? AND ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, $from, $to]);

        return round((float) $statement->fetchColumn(), 2);
    }

    public function accruedBetween(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $from,
        string $to,
    ): float {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND t.type IN (?, ?) AND t.effective_date BETWEEN ? AND ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, 'accrual', 'adjustment', $from, $to]);

        return round((float) $statement->fetchColumn(), 2);
    }

    public function takenBetween(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $from,
        string $to,
    ): float {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(-t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND t.type = ? AND t.effective_date BETWEEN ? AND ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, 'usage', $from, $to]);

        return round((float) $statement->fetchColumn(), 2);
    }

    /** Days credited by posted accrual/fixed-grant transactions only. */
    public function accruedTotal(int $companyId, int $employeeId, int $typeId, string $asOf): float
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND t.type = ? AND t.effective_date <= ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, 'accrual', $asOf]);

        return round((float) $statement->fetchColumn(), 2);
    }

    /** Days consumed by approved requests, before any reversal credits. */
    public function takenTotal(int $companyId, int $employeeId, int $typeId, string $asOf): float
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(-t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND t.type = ? AND t.effective_date <= ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, 'usage', $asOf]);

        return round((float) $statement->fetchColumn(), 2);
    }

    /** A batch with a source key, scoped to its company. */
    public function openingCovering(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $asOf,
    ): ?array {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_batches
             WHERE company_id = ? AND employee_id = ? AND leave_type_id = ?
               AND source = ? AND period_end >= ?
             ORDER BY period_end DESC, id DESC LIMIT 1',
        );
        $statement->execute([$companyId, $employeeId, $typeId, 'opening', $asOf]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** An explicit legacy opening batch for a leave year, if one exists. */
    public function openingForYear(int $companyId, int $employeeId, int $typeId, int $year): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_batches
             WHERE company_id = ? AND employee_id = ? AND leave_type_id = ?
               AND source = ? AND period_end BETWEEN ? AND ?
             LIMIT 1',
        );
        $statement->execute([
            $companyId,
            $employeeId,
            $typeId,
            'opening',
            $year . '-01-01',
            $year . '-12-31',
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function openingBatches(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_batches
             WHERE company_id = ? AND source = ?
             ORDER BY employee_id, leave_type_id, id',
        );
        $statement->execute([$companyId, 'opening']);

        return $statement->fetchAll();
    }

    /** Posted credits (not openings) that fall inside an opening's window. */
    public function postedCreditsThrough(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $asOf,
    ): float {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(t.days), 0)
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND b.source <> ? AND t.type = ? AND b.period_start <= ?',
        );
        $statement->execute([$companyId, $employeeId, $typeId, 'opening', 'accrual', $asOf]);

        return round((float) $statement->fetchColumn(), 2);
    }

    public function hasOpeningReconciliation(int $batchId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM leave_transactions
             WHERE batch_id = ? AND ref_type = ? AND ref_id = ? LIMIT 1',
        );
        $statement->execute([$batchId, 'opening_reconciliation', $batchId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Fixed grants from earlier leave years are not valid under the posted
     * accrual model. Only untouched grants are removed: a batch with usage or
     * an allocation is left for a human to review.
     *
     * @return list<int>
     */
    public function untouchedHistoricalFixedGrants(int $companyId, string $before): array
    {
        $statement = $this->db->prepare(
            'SELECT b.id FROM leave_batches b
             JOIN leave_types lt ON lt.id = b.leave_type_id AND lt.company_id = b.company_id
             WHERE b.company_id = ? AND b.source = ? AND lt.accrual_rule = ?
               AND b.period_start < ?
               AND NOT EXISTS (SELECT 1 FROM leave_request_allocations a WHERE a.batch_id = b.id)
               AND NOT EXISTS (
                   SELECT 1 FROM leave_transactions t
                   WHERE t.batch_id = b.id AND t.type <> ?
               )
             ORDER BY b.id',
        );
        $statement->execute([$companyId, 'grant', 'fixed', $before, 'accrual']);

        return array_map(static fn (array $row): int => (int) $row['id'], $statement->fetchAll());
    }

    public function deleteBatch(int $companyId, int $batchId): void
    {
        $this->db->prepare('DELETE FROM leave_batches WHERE company_id = ? AND id = ?')
            ->execute([$companyId, $batchId]);
    }

    /** @return list<array<string,mixed>> */
    public function batchesForEmployee(int $companyId, int $employeeId): array
    {
        $statement = $this->db->prepare(
            'SELECT b.*,
                    COALESCE((SELECT SUM(t.days) FROM leave_transactions t
                              WHERE t.batch_id = b.id AND t.effective_date <= CURDATE()), 0) AS current_balance
             FROM leave_batches b
             WHERE b.company_id = ? AND b.employee_id = ?
             ORDER BY b.period_start, b.id',
        );
        $statement->execute([$companyId, $employeeId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function recentForEmployee(int $companyId, int $employeeId, int $limit = 12): array
    {
        $statement = $this->db->prepare(
            'SELECT t.type, t.days, t.effective_date, t.note, lt.name AS leave_type
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             JOIN leave_types lt ON lt.id = b.leave_type_id AND lt.company_id = b.company_id
             WHERE b.company_id = ? AND b.employee_id = ?
             ORDER BY t.effective_date DESC, t.id DESC
             LIMIT ' . max(1, min(50, $limit)),
        );
        $statement->execute([$companyId, $employeeId]);

        return $statement->fetchAll();
    }

    /** @return array<int,float> leave type id => ledger balance */
    public function balancesByType(int $companyId, int $employeeId, string $asOf): array
    {
        $statement = $this->db->prepare(
            'SELECT b.leave_type_id, COALESCE(SUM(t.days), 0) AS balance
             FROM leave_transactions t
             JOIN leave_batches b ON b.id = t.batch_id
             WHERE b.company_id = ? AND b.employee_id = ? AND t.effective_date <= ?
             GROUP BY b.leave_type_id',
        );
        $statement->execute([$companyId, $employeeId, $asOf]);
        $balances = [];

        foreach ($statement->fetchAll() as $row) {
            $balances[(int) $row['leave_type_id']] = round((float) $row['balance'], 2);
        }

        return $balances;
    }

    /**
     * Oldest, non-expired batches with their remaining days. The caller must
     * hold a database transaction when using this for a deduction.
     *
     * @return list<array<string,mixed>>
     */
    public function availableBatches(
        int $companyId,
        int $employeeId,
        int $typeId,
        string $asOf,
    ): array {
        $statement = $this->db->prepare(
            'SELECT b.* FROM leave_batches b
             JOIN leave_types lt ON lt.id = b.leave_type_id AND lt.company_id = b.company_id
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND (b.expiry_date IS NULL OR b.expiry_date >= ?)
               AND (lt.accrual_rule <> ? OR b.period_start >= ?)
             ORDER BY b.period_start, b.id
             FOR UPDATE',
        );
        $statement->execute([$companyId, $employeeId, $typeId, $asOf, 'fixed', substr($asOf, 0, 4) . '-01-01']);
        $batches = [];

        foreach ($statement->fetchAll() as $batch) {
            $batch['remaining'] = $this->batchBalance((int) $batch['id'], $asOf);

            if ($batch['remaining'] > 0.0001) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    /** @return list<array<string,mixed>> */
    public function allocationsForRequest(int $companyId, int $requestId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_request_allocations
             WHERE company_id = ? AND request_id = ? ORDER BY id',
        );
        $statement->execute([$companyId, $requestId]);

        return $statement->fetchAll();
    }

    /**
     * FIFO deduction for an approved request. Idempotent when the request
     * already has allocations. The caller owns the transaction.
     *
     * @return list<array{batch_id:int,days:float}>
     */
    public function allocateRequest(
        int $companyId,
        int $requestId,
        int $employeeId,
        int $typeId,
        float $days,
        string $effectiveDate,
        ?int $actorId,
    ): array {
        $existing = $this->allocationsForRequest($companyId, $requestId);

        if ($existing !== []) {
            return array_map(
                static fn (array $row): array => [
                    'batch_id' => (int) $row['batch_id'],
                    'days' => round((float) $row['days'], 2),
                ],
                $existing,
            );
        }

        $remaining = round($days, 2);
        $allocations = [];

        foreach ($this->availableBatches($companyId, $employeeId, $typeId, $effectiveDate) as $batch) {
            if ($remaining <= 0.0001) {
                break;
            }

            $take = min($remaining, (float) $batch['remaining']);

            if ($take <= 0.0001) {
                continue;
            }

            $this->addTransaction(
                $companyId,
                (int) $batch['id'],
                'usage',
                -$take,
                $effectiveDate,
                'leave_request',
                $requestId,
                null,
                $actorId,
                'Leave request approved',
            );
            $this->db
                ->prepare(
                    'INSERT INTO leave_request_allocations
                        (company_id, request_id, batch_id, days)
                     VALUES (?, ?, ?, ?)',
                )
                ->execute([$companyId, $requestId, (int) $batch['id'], round($take, 2)]);

            $allocations[] = ['batch_id' => (int) $batch['id'], 'days' => round($take, 2)];
            $remaining = round($remaining - $take, 2);
        }

        if ($remaining > 0.0001) {
            throw new RuntimeException('Not enough non-expired leave batches to cover this request.');
        }

        return $allocations;
    }

    /**
     * Reverse every usage transaction for a request, on the exact batches
     * originally used. The caller owns the transaction and request state change.
     */
    public function reverseRequest(int $companyId, int $requestId, string $effectiveDate, ?int $actorId): int
    {
        $statement = $this->db->prepare(
            'SELECT t.* FROM leave_transactions t
             WHERE t.company_id = ? AND t.ref_type = ? AND t.ref_id = ? AND t.type = ?
               AND NOT EXISTS (
                   SELECT 1 FROM leave_transactions r WHERE r.reversal_of_id = t.id
               )
             ORDER BY t.id',
        );
        $statement->execute([$companyId, 'leave_request', $requestId, 'usage']);
        $reversed = 0;

        foreach ($statement->fetchAll() as $usage) {
            $this->addTransaction(
                $companyId,
                (int) $usage['batch_id'],
                'reversal',
                -((float) $usage['days']),
                $effectiveDate,
                'leave_request_reversal',
                $requestId,
                (int) $usage['id'],
                $actorId,
                'Leave request reversed',
            );
            $reversed++;
        }

        return $reversed;
    }

    /**
     * Restore an approved request that predates the ledger and therefore has
     * no usage allocation. It becomes a separate opening/adjustment batch so
     * the cancellation is still visible in the append-only history.
     */
    public function restoreLegacyRequest(
        int $companyId,
        int $requestId,
        int $employeeId,
        int $typeId,
        float $days,
        string $effectiveDate,
        ?int $actorId,
    ): void {
        if ($days <= 0.0001) {
            return;
        }

        $sourceKey = 'legacy-reversal:' . $companyId . ':' . $requestId;

        if ($this->batchBySourceKey($companyId, $sourceKey) !== null) {
            return;
        }

        $batchId = $this->createBatch(
            $companyId,
            $employeeId,
            $typeId,
            $effectiveDate,
            $effectiveDate,
            $days,
            null,
            'reversal',
            $sourceKey,
            $actorId,
        );
        $this->addTransaction(
            $companyId,
            $batchId,
            'adjustment',
            $days,
            $effectiveDate,
            'leave_request_reversal',
            $requestId,
            null,
            $actorId,
            'Pre-ledger leave restored on cancellation',
        );
    }

    /** Write an expiry exactly once for a batch. Returns false when already expired. */
    public function expireBatch(int $companyId, int $batchId, string $asOf, ?int $actorId, string $note): bool
    {
        $remaining = $this->batchBalance($batchId, $asOf);

        if ($remaining <= 0.0001) {
            return false;
        }

        $check = $this->db->prepare(
            'SELECT 1 FROM leave_transactions
             WHERE company_id = ? AND batch_id = ? AND type = ? AND ref_type = ? AND ref_id = ? LIMIT 1',
        );
        $check->execute([$companyId, $batchId, 'expiry', 'leave_expiry', $batchId]);

        if ($check->fetchColumn() !== false) {
            return false;
        }

        $this->addTransaction(
            $companyId,
            $batchId,
            'expiry',
            -$remaining,
            $asOf,
            'leave_expiry',
            $batchId,
            null,
            $actorId,
            $note,
        );

        return true;
    }
}