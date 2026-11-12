<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Payroll;

use PDO;

/**
 * Runs and their lines. A run is created once and never edited; approval is
 * the only state change it has.
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
                (company_id, period, label, rate_version_id, employee_count,
                 gross_cents, deductions_cents, net_cents,
                 employer_nssf_cents, employer_housing_cents, employer_nita_cents,
                 employer_cost_cents, prepared_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $companyId,
                $header['period'],
                $header['label'],
                $header['rate_version_id'],
                $header['employee_count'],
                $header['gross_cents'],
                $header['deductions_cents'],
                $header['net_cents'],
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
             deductions_cents, net_cents,
             nssf_employer_cents, housing_levy_employer_cents, nita_cents,
             employer_cost_cents, breakdown)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

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
                $line['nssf_employer_cents'],
                $line['housing_levy_employer_cents'],
                $line['nita_cents'],
                $line['employer_cost_cents'],
                $line['breakdown'],
            ]);
        }
    }

    public function existsForPeriod(int $companyId, string $period): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM payroll_runs WHERE company_id = ? AND period = ? LIMIT 1',
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $runId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, rv.label AS rate_label, rv.effective_from AS rate_effective_from,
                    rv.verified_on AS rate_verified_on,
                    p.first_name AS prepared_first, p.last_name AS prepared_last,
                    a.first_name AS approved_first, a.last_name AS approved_last
             FROM payroll_runs r
             JOIN rate_versions rv ON rv.id = r.rate_version_id
             LEFT JOIN users p ON p.id = r.prepared_by
             LEFT JOIN users a ON a.id = r.approved_by
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
                    r.rate_version_id, r.approved_at
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
                    r.rate_version_id, r.approved_at
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id
             JOIN employees e ON e.id = l.employee_id AND e.company_id = r.company_id
             WHERE r.company_id = ? AND e.user_id = ? AND r.status = ?
             ORDER BY r.period DESC, l.id',
        );
        $statement->execute([$companyId, $userId, 'approved']);

        return $statement->fetchAll();
    }

    /** One approved payslip, only for the employee linked to this login. */
    public function payslipForEmployee(int $companyId, int $userId, int $lineId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, r.period, r.label AS run_label, r.status AS run_status,
                    r.rate_version_id, r.approved_at
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
                    p.first_name AS prepared_first, p.last_name AS prepared_last
             FROM payroll_runs r
             JOIN rate_versions rv ON rv.id = r.rate_version_id
             LEFT JOIN users p ON p.id = r.prepared_by
             WHERE r.company_id = ?
             ORDER BY r.period DESC, r.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** Only an unapproved draft can move. Returns false when it already moved. */
    public function markApproved(int $companyId, int $runId, int $actorId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE payroll_runs
             SET status = ?, approved_by = ?, approved_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND status = ?',
        );
        $statement->execute(['approved', $actorId, $runId, $companyId, 'draft']);

        return $statement->rowCount() === 1;
    }
}
