<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Payroll;

use PDO;

/**
 * Runs and their lines. A regular run is created once; corrections are
 * separate runs linked to the original. Lines are append-only.
 */
final class PayrollRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $header */
    public function create(int $companyId, array $header): int
    {
        $this->db
            ->prepare('INSERT INTO payroll_runs
                (company_id, period, label, run_type, run_no, parent_run_id, adjustment_reason, rate_version_id,
                 employee_count, gross_cents, deductions_cents, net_cents,
                 reimbursements_cents, loan_deductions_cents, other_deductions_cents,
                 commission_cents, service_charge_cents, final_dues_cents,
                 employer_nssf_cents, employer_housing_cents, employer_nita_cents,
                 employer_cost_cents, prepared_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $companyId,
                $header['period'],
                $header['label'],
                $header['run_type'] ?? 'regular',
                $header['run_no'] ?? 1,
                $header['parent_run_id'] ?? null,
                $header['adjustment_reason'] ?? null,
                $header['rate_version_id'],
                $header['employee_count'],
                $header['gross_cents'],
                $header['deductions_cents'],
                $header['net_cents'],
                $header['reimbursements_cents'] ?? 0,
                $header['loan_deductions_cents'] ?? 0,
                $header['other_deductions_cents'] ?? 0,
                $header['commission_cents'] ?? 0,
                $header['service_charge_cents'] ?? 0,
                $header['final_dues_cents'] ?? 0,
                $header['employer_nssf_cents'],
                $header['employer_housing_cents'],
                $header['employer_nita_cents'],
                $header['employer_cost_cents'],
                $header['prepared_by'],
            ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param list<array<string,mixed>> $lines */
    public function addLines(int $runId, array $lines): void
    {
        $statement = $this->db->prepare('INSERT INTO payroll_lines
            (run_id, employee_id, employee_no, employee_name, gross_cents, taxable_cents,
             nssf_employee_cents, shif_cents, housing_levy_employee_cents, paye_cents,
             deductions_cents, net_cents, reimbursements_cents, loan_deduction_cents,
             advance_deduction_cents, other_deduction_cents, commission_cents,
             service_charge_cents, final_dues_cents, proration_bp,
             nssf_employer_cents, housing_levy_employer_cents, nita_cents,
             employer_cost_cents, leave_deduction_cents, holiday_premium_cents, toil_days, breakdown)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($lines as $line) {
            $statement->execute([
                $runId,
                $line['employee_id'],
                $line['employee_no'],
                $line['employee_name'],
                $line['gross_cents'],
                $line['taxable_cents'],
                $line['nssf_employee_cents'],
                $line['shif_cents'],
                $line['housing_levy_employee_cents'],
                $line['paye_cents'],
                $line['deductions_cents'],
                $line['net_cents'],
                $line['reimbursements_cents'] ?? 0,
                $line['loan_deduction_cents'] ?? 0,
                $line['advance_deduction_cents'] ?? 0,
                $line['other_deduction_cents'] ?? 0,
                $line['commission_cents'] ?? 0,
                $line['service_charge_cents'] ?? 0,
                $line['final_dues_cents'] ?? 0,
                $line['proration_bp'] ?? 10000,
                $line['nssf_employer_cents'],
                $line['housing_levy_employer_cents'],
                $line['nita_cents'],
                $line['employer_cost_cents'],
                $line['leave_deduction_cents'] ?? 0,
                $line['holiday_premium_cents'] ?? 0,
                $line['toil_days'] ?? 0,
                $line['breakdown'],
            ]);
        }
    }

    /**
     * @param array<int,list<array<string,mixed>>> $itemsByEmployee
     */
    public function addLineItems(int $runId, array $itemsByEmployee): void
    {
        $find = $this->db->prepare('SELECT id FROM payroll_lines WHERE run_id = ? AND employee_id = ? LIMIT 1');
        $insert = $this->db->prepare(
            'INSERT INTO payroll_line_items
                (company_id, line_id, pay_item_id, label, kind, amount_cents)
             VALUES (?, ?, ?, ?, ?, ?)',
        );

        foreach ($itemsByEmployee as $employeeId => $items) {
            $find->execute([$runId, $employeeId]);
            $lineId = $find->fetchColumn();

            if ($lineId === false) {
                continue;
            }

            foreach ($items as $item) {
                $insert->execute([
                    $item['company_id'],
                    (int) $lineId,
                    $item['pay_item_id'] ?? null,
                    $item['label'],
                    $item['kind'],
                    $item['amount_cents'],
                ]);
            }
        }
    }

    public function addLineItem(int $companyId, int $runId, int $employeeId, array $item): void
    {
        $find = $this->db->prepare('SELECT id FROM payroll_lines WHERE run_id = ? AND employee_id = ? LIMIT 1');
        $find->execute([$runId, $employeeId]);
        $lineId = $find->fetchColumn();

        if ($lineId === false) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO payroll_line_items (company_id, line_id, pay_item_id, label, kind, amount_cents)
             VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            (int) $lineId,
            $item['pay_item_id'] ?? null,
            $item['label'],
            $item['kind'],
            $item['amount_cents'],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function lineItems(int $companyId, int $runId): array
    {
        $statement = $this->db->prepare(
            'SELECT i.*, l.employee_id, l.employee_no, l.employee_name
             FROM payroll_line_items i
             JOIN payroll_lines l ON l.id = i.line_id
             WHERE i.company_id = ? AND l.run_id = ?
             ORDER BY l.employee_name, i.id',
        );
        $statement->execute([$companyId, $runId]);

        return $statement->fetchAll();
    }

    public function existsForPeriod(int $companyId, string $period): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM payroll_runs WHERE company_id = ? AND period = ? AND run_type = "regular" LIMIT 1',
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchColumn() !== false;
    }

    public function nextRunNo(int $companyId, string $period): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(MAX(run_no), 0) + 1 FROM payroll_runs WHERE company_id = ? AND period = ?',
        );
        $statement->execute([$companyId, $period]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $runId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, rv.label AS rate_label, rv.effective_from AS rate_effective_from,
                    rv.verified_on AS rate_verified_on,
                    p.first_name AS prepared_first, p.last_name AS prepared_last,
                    a.first_name AS approved_first, a.last_name AS approved_last,
                    parent.period AS parent_period, parent.label AS parent_label
             FROM payroll_runs r
             JOIN rate_versions rv ON rv.id = r.rate_version_id
             LEFT JOIN users p ON p.id = r.prepared_by
             LEFT JOIN users a ON a.id = r.approved_by
             LEFT JOIN payroll_runs parent ON parent.id = r.parent_run_id AND parent.company_id = r.company_id
             WHERE r.id = ? AND r.company_id = ?
             LIMIT 1',
        );
        $statement->execute([$runId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** One line, only through its run and company. */
    public function line(int $companyId, int $runId, int $lineId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, r.period, r.label AS run_label, r.status AS run_status,
                    r.rate_version_id, r.approved_at, r.run_type, r.run_no
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id
             WHERE l.id = ? AND l.run_id = ? AND r.company_id = ?
             LIMIT 1',
        );
        $statement->execute([$lineId, $runId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Approved payslips for one login, resolved through its employee record.
     *
     * @return list<array<string,mixed>>
     */
    public function payslipsForEmployee(int $companyId, int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, r.period, r.label AS run_label, r.status AS run_status,
                    r.rate_version_id, r.approved_at, r.run_type, r.run_no
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id
             JOIN employees e ON e.id = l.employee_id AND e.company_id = r.company_id
             WHERE r.company_id = ? AND e.user_id = ? AND r.status = ?
             ORDER BY r.period DESC, r.run_no DESC, l.id',
        );
        $statement->execute([$companyId, $userId, 'approved']);

        return $statement->fetchAll();
    }

    /** One approved payslip, only for the employee linked to this login. */
    public function payslipForEmployee(int $companyId, int $userId, int $lineId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, r.period, r.label AS run_label, r.status AS run_status,
                    r.rate_version_id, r.approved_at, r.run_type, r.run_no
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id
             JOIN employees e ON e.id = l.employee_id AND e.company_id = r.company_id
             WHERE l.id = ? AND r.company_id = ? AND e.user_id = ? AND r.status = ?
             LIMIT 1',
        );
        $statement->execute([$lineId, $companyId, $userId, 'approved']);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $runId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM payroll_lines WHERE run_id = ? ORDER BY employee_name, employee_no',
        );
        $statement->execute([$runId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function listForCompany(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, rv.label AS rate_label,
                    p.first_name AS prepared_first, p.last_name AS prepared_last,
                    parent.label AS parent_label
             FROM payroll_runs r
             JOIN rate_versions rv ON rv.id = r.rate_version_id
             LEFT JOIN users p ON p.id = r.prepared_by
             LEFT JOIN payroll_runs parent ON parent.id = r.parent_run_id AND parent.company_id = r.company_id
             WHERE r.company_id = ?
             ORDER BY r.period DESC, r.run_no DESC, r.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** Only an unapproved draft can move. Returns false when it already moved. */
    public function markApproved(int $companyId, int $runId, int $actorId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE payroll_runs
             SET status = ?, approved_by = ?, approved_at = UTC_TIMESTAMP(),
                 locked_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND status = ?',
        );
        $statement->execute(['approved', $actorId, $runId, $companyId, 'draft']);

        return $statement->rowCount() === 1;
    }
}
