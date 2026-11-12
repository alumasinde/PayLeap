<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;

/**
 * Expense claims, damage recovery, commissions, service charge and final dues.
 * Payroll consumes approved rows for a period and marks them settled only
 * after the run that contains them has been created.
 */
final class ClaimRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function expenses(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, e.employee_no, e.first_name, e.last_name
             FROM expense_claims c
             JOIN employees e ON e.id = c.employee_id AND e.company_id = c.company_id
             WHERE c.company_id = ?
             ORDER BY c.status = "pending" DESC, c.created_at DESC, c.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function createExpense(int $companyId, array $data): int
    {
        $this->db->prepare(
            'INSERT INTO expense_claims
                (company_id, employee_id, category, `period`, amount_cents, receipt_ref, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            $data['employee_id'],
            $data['category'],
            $data['period'],
            $data['amount_cents'],
            $data['receipt_ref'] === '' ? null : $data['receipt_ref'],
            'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function decideExpense(int $companyId, int $id, string $decision, int $actorId): void
    {
        $status = $decision === 'approved' ? 'approved' : 'rejected';
        $this->db->prepare(
            'UPDATE expense_claims SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND status = "pending"',
        )->execute([$status, $actorId, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function expensesForPeriod(int $companyId, string $period, bool $includeConsumed = false): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM expense_claims
             WHERE company_id = ? AND `period` = ? AND status = "approved"'
            . ($includeConsumed ? '' : ' AND run_id IS NULL'),
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchAll();
    }

    public function markExpensePaid(int $companyId, int $id, int $runId): void
    {
        $this->db->prepare(
            'UPDATE expense_claims SET run_id = ?, paid_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND status = "approved" AND run_id IS NULL',
        )->execute([$runId, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function damageClaims(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, e.employee_no, e.first_name, e.last_name
             FROM loss_damage_claims c
             JOIN employees e ON e.id = c.employee_id AND e.company_id = c.company_id
             WHERE c.company_id = ?
             ORDER BY c.status = "pending" DESC, c.created_at DESC, c.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function createDamage(int $companyId, array $data): int
    {
        $this->db->prepare(
            'INSERT INTO loss_damage_claims
                (company_id, employee_id, description, amount_cents, recover_via_payroll, status)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            $data['employee_id'],
            $data['description'],
            $data['amount_cents'],
            $data['recover_via_payroll'],
            'pending',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function decideDamage(int $companyId, int $id, string $decision): void
    {
        $status = $decision === 'approved' ? 'approved' : 'rejected';
        $this->db->prepare(
            'UPDATE loss_damage_claims SET status = ? WHERE id = ? AND company_id = ? AND status = "pending"',
        )->execute([$status, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function damageForPeriod(int $companyId, bool $includeConsumed = false): array
    {
        $statement = $this->db->prepare(
            'SELECT *, (amount_cents - recovered_cents) AS due_cents
             FROM loss_damage_claims
             WHERE company_id = ? AND status = "approved" AND recover_via_payroll = 1'
            . ($includeConsumed ? '' : ' AND recovered_cents < amount_cents'),
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function markDamageRecovered(int $companyId, int $id, int $runId, int $amountCents): void
    {
        $this->db->prepare(
            'UPDATE loss_damage_claims
             SET recovered_cents = LEAST(amount_cents, recovered_cents + ?), run_id = ?
             WHERE id = ? AND company_id = ? AND status = "approved" AND recovered_cents < amount_cents',
        )->execute([$amountCents, $runId, $id, $companyId]);

        $this->db->prepare(
            'UPDATE loss_damage_claims
             SET recovered_at = IF(recovered_cents >= amount_cents, UTC_TIMESTAMP(), recovered_at)
             WHERE id = ? AND company_id = ?',
        )->execute([$id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function commissions(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT c.*, e.employee_no, e.first_name, e.last_name
             FROM commission_entries c
             JOIN employees e ON e.id = c.employee_id AND e.company_id = c.company_id
             WHERE c.company_id = ?
             ORDER BY c.period DESC, c.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function createCommission(int $companyId, array $data): int
    {
        $this->db->prepare(
            'INSERT INTO commission_entries
                (company_id, employee_id, period, base_cents, amount_cents)
             VALUES (?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            $data['employee_id'],
            $data['period'],
            $data['base_cents'],
            $data['amount_cents'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function commissionsForPeriod(int $companyId, string $period, bool $includeConsumed = false): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM commission_entries
             WHERE company_id = ? AND period = ?'
            . ($includeConsumed ? '' : ' AND run_id IS NULL'),
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchAll();
    }

    public function markCommissionPaid(int $companyId, int $id, int $runId): void
    {
        $this->db->prepare(
            'UPDATE commission_entries SET run_id = ?, paid_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND run_id IS NULL',
        )->execute([$runId, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function servicePools(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT p.*, COUNT(s.id) AS employee_count, COALESCE(SUM(s.amount_cents), 0) AS allocated_cents
             FROM service_charge_pools p
             LEFT JOIN service_charge_shares s ON s.pool_id = p.id AND s.company_id = p.company_id
             WHERE p.company_id = ?
             GROUP BY p.id
             ORDER BY p.period DESC, p.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** Create a pool and divide it equally between active employees. */
    public function createServicePool(int $companyId, string $period, int $totalCents): int
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'INSERT INTO service_charge_pools (company_id, period, total_cents, rule)
                 VALUES (?, ?, ?, ?)',
            )->execute([$companyId, $period, $totalCents, json_encode(['method' => 'equal'])]);

            $poolId = (int) $this->db->lastInsertId();
            $employees = $this->db->prepare(
                'SELECT id FROM employees WHERE company_id = ? AND status = "active" ORDER BY id',
            );
            $employees->execute([$companyId]);
            $ids = array_map('intval', $employees->fetchAll(PDO::FETCH_COLUMN));

            if ($ids !== []) {
                $each = intdiv($totalCents, count($ids));
                $remainder = $totalCents - ($each * count($ids));

                foreach ($ids as $index => $employeeId) {
                    $amount = $each + ($index === 0 ? $remainder : 0);
                    $this->db->prepare(
                        'INSERT INTO service_charge_shares
                            (company_id, pool_id, employee_id, points, amount_cents)
                         VALUES (?, ?, ?, 1.00, ?)',
                    )->execute([$companyId, $poolId, $employeeId, $amount]);
                }
            }

            if ($own) {
                $this->db->commit();
            }

            return $poolId;
        } catch (\Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function serviceForPeriod(int $companyId, string $period, bool $includeConsumed = false): array
    {
        $statement = $this->db->prepare(
            'SELECT s.*, p.period
             FROM service_charge_shares s
             JOIN service_charge_pools p ON p.id = s.pool_id AND p.company_id = s.company_id
             WHERE s.company_id = ? AND p.period = ?'
            . ($includeConsumed ? '' : ' AND s.run_id IS NULL'),
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchAll();
    }

    public function markServicePaid(int $companyId, int $id, int $runId): void
    {
        $this->db->prepare(
            'UPDATE service_charge_shares SET run_id = ?, paid_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND run_id IS NULL',
        )->execute([$runId, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function finalDues(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT d.*, e.employee_no, e.first_name, e.last_name
             FROM final_dues d
             JOIN employees e ON e.id = d.employee_id AND e.company_id = d.company_id
             WHERE d.company_id = ?
             ORDER BY d.status = "draft" DESC, d.created_at DESC, d.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function createFinalDue(int $companyId, array $data): int
    {
        $total = array_sum([
            $data['leave_payout_cents'],
            $data['notice_cents'],
            $data['severance_cents'],
            $data['service_pay_cents'],
            $data['other_cents'],
        ]);

        $this->db->prepare(
            'INSERT INTO final_dues
                (company_id, employee_id, period, leave_payout_cents, notice_cents,
                 severance_cents, service_pay_cents, other_cents, total_cents, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            $data['employee_id'],
            $data['period'],
            $data['leave_payout_cents'],
            $data['notice_cents'],
            $data['severance_cents'],
            $data['service_pay_cents'],
            $data['other_cents'],
            $total,
            'draft',
            $data['notes'] === '' ? null : $data['notes'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function decideFinalDue(int $companyId, int $id, string $decision): void
    {
        $status = $decision === 'approved' ? 'approved' : 'rejected';
        $this->db->prepare(
            'UPDATE final_dues SET status = ? WHERE id = ? AND company_id = ? AND status = "draft"',
        )->execute([$status, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function finalDuesForPeriod(int $companyId, string $period, bool $includeConsumed = false): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM final_dues
             WHERE company_id = ? AND period = ? AND status = "approved"'
            . ($includeConsumed ? '' : ' AND run_id IS NULL'),
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchAll();
    }

    public function markFinalDuePaid(int $companyId, int $id, int $runId): void
    {
        $this->db->prepare(
            'UPDATE final_dues SET run_id = ?, paid_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND status = "approved" AND run_id IS NULL',
        )->execute([$runId, $id, $companyId]);
    }
}
