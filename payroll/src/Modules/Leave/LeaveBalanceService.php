<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

/**
 * Read model for leave balances. The ledger is authoritative for available
 * days; pending requests are shown separately and are never written to it
 * until an approval allocates them.
 */
final class LeaveBalanceService
{
    public function __construct(
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
            $from = ($row['accrual_rule'] ?? '') === 'fixed' ? $year . '-01-01' : '1900-01-01';
            $ledgerBalance = $this->ledger->balanceBetween($companyId, $employeeId, (int) $row['id'], $from, $asOf);
            $pending = (float) $row['pending'];
            $accrued = $this->ledger->accruedBetween($companyId, $employeeId, (int) $row['id'], $from, $asOf);
            $taken = $this->ledger->takenBetween($companyId, $employeeId, (int) $row['id'], $from, $asOf);
            $rows[$index]['ledger_balance'] = $ledgerBalance;
            $rows[$index]['balance'] = $ledgerBalance;
            $rows[$index]['accrued'] = $accrued;
            $rows[$index]['taken'] = $taken;
            $rows[$index]['pending'] = $pending;
            $rows[$index]['available'] = round($ledgerBalance - $pending, 2);
            $rows[$index]['remaining'] = $ledgerBalance;
        }

        return $rows;
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