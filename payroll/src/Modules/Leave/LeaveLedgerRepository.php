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
             WHERE b.company_id = ? AND b.employee_id = ? AND b.leave_type_id = ?
               AND (b.expiry_date IS NULL OR b.expiry_date >= ?)
             ORDER BY b.period_start, b.id
             FOR UPDATE',
        );
        $statement->execute([$companyId, $employeeId, $typeId, $asOf]);
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
}