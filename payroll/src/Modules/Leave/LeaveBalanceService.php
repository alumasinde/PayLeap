<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;

/**
 * Read model for leave balances. The ledger is authoritative for available
 * days; pending requests are shown separately and are never written to it
 * until an approval allocates them.
 */
final class LeaveBalanceService
{
    public function __construct(
        private readonly PDO $db,
        private readonly LeaveRepository $requests,
        private readonly LeaveLedgerRepository $ledger,
    ) {
    }

    /** Ledger balance from transactions only, for one type as of a date. */
    public function balance(int $companyId, int $employeeId, int $typeId, string $asOf): float
    {
        return $this->ledger->balance($companyId, $employeeId, $typeId, $asOf);
    }

    /**
     * Balances for every active type, with the existing entitlement/accrual
     * projection and the ledger-backed available amount.
     *
     * @return list<array<string,mixed>>
     */
    public function balances(int $companyId, int $employeeId, int $year, ?string $asOf = null): array
    {
        $asOf = $this->date($asOf);
        $rows = $this->requests->balances($companyId, $employeeId, $year, $asOf);

        foreach ($rows as $index => $row) {
            $this->ensureOpening(
                $companyId,
                $employeeId,
                (int) $row['id'],
                $year,
                $asOf,
                (float) $row['accrued'],
                (float) $row['taken'],
            );
            $ledgerBalance = $this->ledger->balance($companyId, $employeeId, (int) $row['id'], $asOf);
            $pending = (float) $row['pending'];
            $rows[$index]['ledger_balance'] = $ledgerBalance;
            $rows[$index]['balance'] = $ledgerBalance;
            $rows[$index]['available'] = round($ledgerBalance - $pending, 2);
        }

        return $rows;
    }

    /**
     * Idempotent opening batch for a type. It records the balance that existed
     * before the ledger was introduced, excluding pending requests because
     * pending is not deducted until approval.
     */
    public function ensureOpening(
        int $companyId,
        int $employeeId,
        int $typeId,
        int $year,
        string $asOf,
        float $accrued,
        float $taken,
    ): void {
        $sourceKey = 'opening:' . $companyId . ':' . $employeeId . ':' . $typeId . ':' . $year;

        if ($this->ledger->batchBySourceKey($companyId, $sourceKey) !== null) {
            return;
        }

        $opening = round($accrued - $taken, 2);

        if ($opening <= 0.0001) {
            return;
        }

        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $batchId = $this->ledger->createBatch(
                $companyId,
                $employeeId,
                $typeId,
                $year . '-01-01',
                $asOf,
                $opening,
                null,
                'opening',
                $sourceKey,
                null,
            );
            $this->ledger->addTransaction(
                $companyId,
                $batchId,
                'accrual',
                $opening,
                $asOf,
                'opening_balance',
                null,
                null,
                null,
                'Opening balance before the leave ledger',
            );

            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // A concurrent read may have won the unique source key.
            if ($this->ledger->batchBySourceKey($companyId, $sourceKey) === null) {
                throw $error;
            }
        }
    }

    /** Idempotently create opening batches for every employee in a company. */
    public function backfillCompany(int $companyId): int
    {
        $year = (int) date('Y');
        $asOf = date('Y-m-d');
        $created = 0;

        foreach ($this->requests->employeeIds($companyId) as $employeeId) {
            foreach ($this->requests->types($companyId) as $type) {
                $rows = $this->requests->balances($companyId, $employeeId, $year, $asOf);
                $row = null;

                foreach ($rows as $candidate) {
                    if ((int) $candidate['id'] === (int) $type['id']) {
                        $row = $candidate;
                        break;
                    }
                }

                if ($row === null) {
                    continue;
                }

                $sourceKey = 'opening:' . $companyId . ':' . $employeeId . ':' . $type['id'] . ':' . $year;

                if ($this->ledger->batchBySourceKey($companyId, $sourceKey) !== null) {
                    continue;
                }

                $before = $this->ledger->batchBySourceKey($companyId, $sourceKey);
                $this->ensureOpening(
                    $companyId,
                    $employeeId,
                    (int) $type['id'],
                    $year,
                    $asOf,
                    (float) $row['accrued'],
                    (float) $row['taken'],
                );

                if ($before === null && $this->ledger->batchBySourceKey($companyId, $sourceKey) !== null) {
                    $created++;
                }
            }
        }

        return $created;
    }

    private function date(?string $value): string
    {
        if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($date !== false && $date->format('Y-m-d') === $value) {
                return $value;
            }
        }

        return date('Y-m-d');
    }
}