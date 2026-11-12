<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;
use Throwable;

/**
 * Loans, advances and their repayment schedules. A schedule row is the
 * authoritative amount due in a period; payroll applies it and records the
 * amount actually recovered.
 */
final class LoanRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, e.employee_no, e.first_name, e.last_name,
                    COALESCE(SUM(CASE WHEN s.paid = 0 THEN s.principal_cents + s.interest_cents - s.paid_cents ELSE 0 END), 0) AS balance_cents,
                    MIN(CASE WHEN s.paid = 0 THEN s.due_period END) AS next_due_period
             FROM loans l
             JOIN employees e ON e.id = l.employee_id AND e.company_id = l.company_id
             LEFT JOIN loan_schedule s ON s.loan_id = l.id AND s.company_id = l.company_id
             WHERE l.company_id = ?
             GROUP BY l.id
             ORDER BY l.status = "settled", l.created_at DESC, l.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function find(int $companyId, int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, e.employee_no, e.first_name, e.last_name
             FROM loans l
             JOIN employees e ON e.id = l.employee_id AND e.company_id = l.company_id
             WHERE l.id = ? AND l.company_id = ? LIMIT 1',
        );
        $statement->execute([$id, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function schedule(int $companyId, int $loanId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM loan_schedule WHERE loan_id = ? AND company_id = ? ORDER BY due_period, id',
        );
        $statement->execute([$loanId, $companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function advances(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT a.*, e.employee_no, e.first_name, e.last_name, l.reference AS loan_reference
             FROM advance_requests a
             JOIN employees e ON e.id = a.employee_id AND e.company_id = a.company_id
             LEFT JOIN loans l ON l.id = a.loan_id
             WHERE a.company_id = ?
             ORDER BY a.status = "pending" DESC, a.created_at DESC, a.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function createAdvance(int $companyId, array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO advance_requests
                (company_id, employee_id, amount_cents, reason, start_period, installments, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $companyId,
            $data['employee_id'],
            $data['amount_cents'],
            $data['reason'] === '' ? null : $data['reason'],
            $data['start_period'],
            $data['installments'],
            'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findAdvance(int $companyId, int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM advance_requests WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$id, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Approve an advance and turn it into a loan with a schedule.
     *
     * @return int loan id
     */
    public function approveAdvance(int $companyId, int $id, int $actorId): int
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $advance = $this->findAdvance($companyId, $id);

            if ($advance === null || $advance['status'] !== 'pending') {
                throw new \RuntimeException('That advance request is not pending.');
            }

            $loanId = $this->create(
                $companyId,
                (int) $advance['employee_id'],
                (int) $advance['amount_cents'],
                0,
                max(1, (int) $advance['installments']),
                (string) ($advance['start_period'] ?: date('Y-m')),
                'advance',
                'ADV-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                $advance['reason'] === null ? null : (string) $advance['reason'],
            );

            $this->db->prepare(
                'UPDATE advance_requests SET status = ?, loan_id = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
                 WHERE id = ? AND company_id = ? AND status = ?',
            )->execute(['approved', $loanId, $actorId, $id, $companyId, 'pending']);

            if ($own) {
                $this->db->commit();
            }

            return $loanId;
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    public function rejectAdvance(int $companyId, int $id, int $actorId): void
    {
        $this->db->prepare(
            'UPDATE advance_requests SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND status = ?',
        )->execute(['rejected', $actorId, $id, $companyId, 'pending']);
    }

    /** Build the loan and an equal-installment schedule. */
    public function create(
        int $companyId,
        int $employeeId,
        int $principalCents,
        int $rateBp,
        int $installments,
        string $startPeriod,
        string $type,
        ?string $reference,
        ?string $notes,
    ): int {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $statement = $this->db->prepare(
                'INSERT INTO loans
                    (company_id, employee_id, reference, type, principal_cents, rate_bp, installments, start_period, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $companyId,
                $employeeId,
                $reference,
                $type,
                $principalCents,
                $rateBp,
                $installments,
                $startPeriod,
                'active',
                $notes,
            ]);
            $loanId = (int) $this->db->lastInsertId();

            $interest = intdiv($principalCents * $rateBp, 10000);
            $total = $principalCents + $interest;
            $installment = (int) ceil($total / max(1, $installments));
            $remaining = $total;
            $principalRemaining = $principalCents;
            $period = $startPeriod;

            for ($i = 1; $i <= $installments; $i++) {
                $amount = $i === $installments ? $remaining : $installment;
                $principalPart = $i === $installments
                    ? $principalRemaining
                    : (int) round($principalCents / $installments);
                $principalPart = min($principalPart, $principalRemaining);
                $interestPart = max(0, $amount - $principalPart);

                $this->db->prepare(
                    'INSERT INTO loan_schedule
                        (company_id, loan_id, due_period, principal_cents, interest_cents, paid, paid_cents)
                     VALUES (?, ?, ?, ?, ?, 0, 0)',
                )->execute([$companyId, $loanId, $period, $principalPart, $interestPart]);

                $remaining -= $amount;
                $principalRemaining -= $principalPart;
                $period = date('Y-m', strtotime($period . '-01 +1 month'));
            }

            if ($own) {
                $this->db->commit();
            }

            return $loanId;
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /**
     * Unpaid or partly paid schedule rows due by the period, grouped by loan.
     *
     * @return list<array<string,mixed>>
     */
    public function dueForPeriod(int $companyId, string $period, bool $includePaid = false): array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, l.id AS loan_id, l.employee_id, l.type, l.reference
             FROM loan_schedule s
             JOIN loans l ON l.id = s.loan_id AND l.company_id = s.company_id
             WHERE s.company_id = ? AND l.status IN ("active", "settled")
               AND s.due_period <= ?' . ($includePaid ? '' : ' AND s.paid = 0') . '
             ORDER BY l.employee_id, s.due_period, s.id',
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchAll();
    }

    /** Record a recovery against one schedule row. */
    public function applyRecovery(int $companyId, int $scheduleId, int $amountCents): void
    {
        $this->db->prepare(
            'UPDATE loan_schedule
             SET paid_cents = LEAST(principal_cents + interest_cents, paid_cents + ?)
             WHERE id = ? AND company_id = ?',
        )->execute([$amountCents, $scheduleId, $companyId]);

        $this->db->prepare(
            'UPDATE loan_schedule
             SET paid = (paid_cents >= principal_cents + interest_cents)
             WHERE id = ? AND company_id = ?',
        )->execute([$scheduleId, $companyId]);
    }

    public function settle(int $companyId, int $loanId): void
    {
        $this->db->prepare(
            'UPDATE loans SET status = "settled"
             WHERE id = ? AND company_id = ? AND NOT EXISTS (
                 SELECT 1 FROM loan_schedule s WHERE s.loan_id = loans.id AND s.paid = 0
             )',
        )->execute([$loanId, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function balances(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, e.employee_no, e.first_name, e.last_name,
                    COALESCE(SUM(s.principal_cents + s.interest_cents - s.paid_cents), 0) AS balance_cents,
                    SUM(CASE WHEN s.paid = 0 THEN 1 ELSE 0 END) AS remaining_installments
             FROM loans l
             JOIN employees e ON e.id = l.employee_id AND e.company_id = l.company_id
             LEFT JOIN loan_schedule s ON s.loan_id = l.id AND s.company_id = l.company_id
             WHERE l.company_id = ? AND l.status = "active"
             GROUP BY l.id
             ORDER BY balance_cents DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }
}
