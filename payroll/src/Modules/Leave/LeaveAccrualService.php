<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use DateTimeImmutable;
use PDO;

/**
 * Posts leave grants into the ledger. Nothing here runs from a GET: the month
 * job, the CLI and state-changing leave actions call it explicitly. Every batch
 * has a deterministic source key, so retries are safe.
 */
final class LeaveAccrualService
{
    public function __construct(
        private readonly PDO $db,
        private readonly LeaveRepository $requests,
        private readonly LeaveLedgerRepository $ledger,
        private readonly LeaveSettingsRepository $settings,
    ) {
    }

    /** Post one calendar month for every eligible employee in a company. */
    public function postMonth(int $companyId, int $year, int $month, ?int $actorId = null): int
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return 0;
        }

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');

        if ($start > new DateTimeImmutable('today')) {
            return 0;
        }

        $created = 0;

        foreach ($this->requests->employeesForAccrual($companyId, $end->format('Y-m-d')) as $employee) {
            $created += $this->postEmployeeMonth($companyId, $employee, $start, $end, $actorId);
        }

        return $created;
    }

    /**
     * Fill every month from an employee's start through the date, used when
     * somebody first interacts after the monthly job has not run yet.
     */
    public function postEmployeeThrough(
        int $companyId,
        int $employeeId,
        string $asOf,
        ?int $actorId = null,
        bool $fixedOnly = false,
    ): int
    {
        $through = $this->date($asOf) ?? new DateTimeImmutable('today');
        $created = 0;

        foreach ($this->requests->employeesForAccrual($companyId, $through->format('Y-m-d')) as $employee) {
            if ((int) $employee['id'] !== $employeeId) {
                continue;
            }

            $start = $this->date((string) ($employee['start_date'] ?? ''));

            if ($start === null) {
                return 0;
            }

            $cursor = $start->modify('first day of this month');

            while ($cursor <= $through) {
                $monthEnd = $cursor->modify('last day of this month');
                $created += $this->postEmployeeMonth($companyId, $employee, $cursor, $monthEnd, $actorId, $fixedOnly);
                $cursor = $cursor->modify('first day of next month');
            }

            return $created;
        }

        return 0;
    }

    /** Post every employee from their start through the given date. */
    public function postThrough(int $companyId, string $asOf, ?int $actorId = null): int
    {
        $through = $this->date($asOf) ?? new DateTimeImmutable('today');
        $created = 0;

        foreach ($this->requests->employeesForAccrual($companyId, $through->format('Y-m-d')) as $employee) {
            $start = $this->date((string) ($employee['start_date'] ?? ''));

            if ($start === null) {
                continue;
            }

            $cursor = $start->modify('first day of this month');

            while ($cursor <= $through) {
                $monthEnd = $cursor->modify('last day of this month');
                $created += $this->postEmployeeMonth($companyId, $employee, $cursor, $monthEnd, $actorId);
                $cursor = $cursor->modify('first day of next month');
            }
        }

        return $created;
    }

    /** @param array<string,mixed> $employee */
    private function postEmployeeMonth(
        int $companyId,
        array $employee,
        DateTimeImmutable $monthStart,
        DateTimeImmutable $monthEnd,
        ?int $actorId,
        bool $fixedOnly = false,
    ): int {
        $start = $this->date((string) ($employee['start_date'] ?? ''));
        $exit = $this->date((string) ($employee['exit_date'] ?? ''));

        if ($start === null || $start > $monthEnd || ($exit !== null && $exit < $monthStart)) {
            return 0;
        }

        $created = 0;
        $year = (int) $monthEnd->format('Y');
        $monthKey = $monthEnd->format('Y-m');
        $effective = $monthEnd > new DateTimeImmutable('today')
            ? new DateTimeImmutable('today')
            : $monthEnd;
        $previousEnd = $monthStart->modify('-1 day');

        foreach ($this->requests->types($companyId) as $type) {
            $typeId = (int) $type['id'];
            $rule = (string) ($type['accrual_rule'] ?? 'fixed');

            if ($rule === 'monthly') {
                if ($fixedOnly) {
                    continue;
                }

                $target = (float) LeaveAccrual::calculate(
                    $type,
                    $start->format('Y-m-d'),
                    $year,
                    $effective->format('Y-m-d'),
                )['accrued'];
                $before = (float) LeaveAccrual::calculate(
                    $type,
                    $start->format('Y-m-d'),
                    $year,
                    $previousEnd->format('Y-m-d'),
                )['accrued'];
                $days = round($target - $before, 2);

                if ($days <= 0.0001) {
                    continue;
                }

                if ($this->ledger->openingCovering($companyId, (int) $employee['id'], $typeId, $effective->format('Y-m-d')) !== null) {
                    continue;
                }

                $sourceKey = 'accrual:' . $companyId . ':' . $employee['id'] . ':' . $typeId . ':' . $monthKey;

                if ($this->ledger->batchBySourceKey($companyId, $sourceKey) !== null) {
                    continue;
                }

                $batchId = $this->ledger->createBatch(
                    $companyId,
                    (int) $employee['id'],
                    $typeId,
                    $monthStart->format('Y-m-d'),
                    $monthEnd->format('Y-m-d'),
                    $days,
                    $this->expiryFor($type, $year),
                    'accrual',
                    $sourceKey,
                    $actorId,
                );
                $this->ledger->addTransaction(
                    $companyId,
                    $batchId,
                    'accrual',
                    $days,
                    $effective->format('Y-m-d'),
                    'leave_accrual',
                    $typeId,
                    null,
                    $actorId,
                    'Monthly leave accrual for ' . $monthKey,
                );
                $created++;

                continue;
            }

            // Fixed grants are posted once, at the first month in which the
            // type becomes available. The source key is the leave year.
            // Fixed types are event/period entitlements (maternity, paternity,
            // pre-adoptive, sick and compassionate). Only the current leave year
            // is granted: they must not accumulate a new full grant for every
            // historical year when the ledger is first populated.
            if ($year < (int) date('Y')) {
                continue;
            }

            // A legacy opening already includes this year's fixed grant.
            if ($this->ledger->openingForYear($companyId, (int) $employee['id'], $typeId, $year) !== null) {
                continue;
            }

            $target = (float) LeaveAccrual::calculate(
                $type,
                $start->format('Y-m-d'),
                $year,
                $monthEnd->format('Y-m-d'),
            )['accrued'];
            if ($target <= 0.0001) {
                continue;
            }

            if ($this->ledger->openingCovering($companyId, (int) $employee['id'], $typeId, $monthEnd->format('Y-m-d')) !== null) {
                continue;
            }

            $sourceKey = 'fixed:' . $companyId . ':' . $employee['id'] . ':' . $typeId . ':' . $year;

            if ($this->ledger->batchBySourceKey($companyId, $sourceKey) !== null) {
                continue;
            }

            $batchId = $this->ledger->createBatch(
                $companyId,
                (int) $employee['id'],
                $typeId,
                $year . '-01-01',
                $year . '-12-31',
                $target,
                $this->expiryFor($type, $year),
                'grant',
                $sourceKey,
                $actorId,
            );
            $this->ledger->addTransaction(
                $companyId,
                $batchId,
                'accrual',
                $target,
                $year > (int) date('Y')
                    ? $year . '-01-01'
                    : $effective->format('Y-m-d'),
                'leave_grant',
                $typeId,
                null,
                $actorId,
                'Annual ' . $type['name'] . ' grant for ' . $year,
            );
            $created++;
        }

        return $created;
    }

    /**
     * Expire or flag batches after their 18-month window. A tenant that has not
     * opted into forfeiture gets an HR review row and keeps the days; this is
     * the guard against silently losing carry-over.
     */
    public function expire(int $companyId, string $asOf, ?int $actorId = null): int
    {
        $settings = $this->settings->forCompany($companyId);
        $forfeit = (int) $settings['forfeit_excess'] === 1;

        $statement = $this->db->prepare(
            'SELECT b.*,
                    COALESCE((SELECT SUM(t.days) FROM leave_transactions t
                              WHERE t.batch_id = b.id AND t.effective_date <= ?), 0) AS remaining
             FROM leave_batches b
             WHERE b.company_id = ? AND b.expiry_date IS NOT NULL AND b.expiry_date <= ?
             ORDER BY b.expiry_date, b.id',
        );
        $statement->execute([$asOf, $companyId, $asOf]);

        $processed = 0;

        foreach ($statement->fetchAll() as $batch) {
            $remaining = round((float) $batch['remaining'], 2);

            if ($remaining <= 0.0001) {
                continue;
            }

            if ($forfeit) {
                if ($this->ledger->expireBatch($companyId, (int) $batch['id'], $asOf, $actorId, 'Leave expired after its carry-over window')) {
                    $processed++;
                }

                continue;
            }

            $this->db->prepare(
                'INSERT INTO leave_exceptions
                    (company_id, employee_id, batch_id, leave_type_id, kind, days, status, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE days = VALUES(days)',
            )->execute([
                $companyId,
                (int) $batch['employee_id'],
                (int) $batch['id'],
                (int) $batch['leave_type_id'],
                'expiry',
                $remaining,
                'pending',
                'Expired carry-over needs HR review; forfeiture is disabled for this company.',
            ]);

            $processed++;
        }

        return $processed;
    }

    /** @return list<array<string,mixed>> */
    public function exceptions(int $companyId, string $status = 'pending'): array
    {
        $sql = 'SELECT x.*, e.first_name, e.last_name, e.employee_no, lt.name AS leave_type,
                       b.period_start, b.period_end, b.expiry_date
                FROM leave_exceptions x
                JOIN employees e ON e.id = x.employee_id AND e.company_id = x.company_id
                JOIN leave_types lt ON lt.id = x.leave_type_id AND lt.company_id = x.company_id
                JOIN leave_batches b ON b.id = x.batch_id
                WHERE x.company_id = ?';
        $params = [$companyId];

        if (in_array($status, ['pending', 'resolved'], true)) {
            $sql .= ' AND x.status = ?';
            $params[] = $status;
        }

        $statement = $this->db->prepare($sql . ' ORDER BY x.id DESC LIMIT 200');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function resolveException(int $companyId, int $exceptionId, string $action, int $actorId): void
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_exceptions WHERE id = ? AND company_id = ? AND status = ? LIMIT 1',
        );
        $statement->execute([$exceptionId, $companyId, 'pending']);
        $exception = $statement->fetch();

        if ($exception === false) {
            throw new \Mshahara\Payroll\Support\HttpException(404, 'That leave review item does not exist.');
        }

        if ($action === 'forfeit') {
            $this->ledger->expireBatch(
                $companyId,
                (int) $exception['batch_id'],
                date('Y-m-d'),
                $actorId,
                'Carry-over forfeited after HR review',
            );
        }

        $this->db->prepare(
            'UPDATE leave_exceptions
             SET status = ?, resolved_at = UTC_TIMESTAMP(), resolved_by = ?, note = ?
             WHERE id = ? AND company_id = ? AND status = ?',
        )->execute([
            'resolved',
            $actorId,
            $action === 'forfeit' ? 'Forfeited by HR' : 'Retained by HR',
            $exceptionId,
            $companyId,
            'pending',
        ]);
    }

    /** @param array<string,mixed> $type */
    private function expiryFor(array $type, int $year): string
    {
        $months = max(0, min(60, (int) ($type['expiry_months'] ?? 18)));
        $end = (new DateTimeImmutable($year . '-12-31'))->modify('+' . $months . ' months');

        return $end->format('Y-m-d');
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}
