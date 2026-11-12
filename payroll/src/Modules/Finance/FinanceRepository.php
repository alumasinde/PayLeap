<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;

/**
 * Read models for the finance dashboard, reports and the general ledger.
 * Nothing here changes payroll; it explains what payroll already recorded.
 */
final class FinanceRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function dashboard(int $companyId): array
    {
        $run = $this->db->prepare(
            'SELECT * FROM payroll_runs
             WHERE company_id = ? AND run_type = "regular"
             ORDER BY period DESC, run_no DESC, id DESC LIMIT 1',
        );
        $run->execute([$companyId]);
        $latest = $run->fetch() ?: null;

        $previous = $this->db->prepare(
            'SELECT * FROM payroll_runs
             WHERE company_id = ? AND run_type = "regular" AND status = "approved"
             ORDER BY period DESC, run_no DESC, id DESC LIMIT 1 OFFSET 1',
        );
        $previous->execute([$companyId]);
        $prior = $previous->fetch() ?: null;

        $unpaid = $this->db->prepare(
            'SELECT COUNT(*) AS count, COALESCE(SUM(net_cents), 0) AS net_cents,
                    COALESCE(SUM(employer_cost_cents), 0) AS employer_cents
             FROM payroll_runs WHERE company_id = ? AND status = "approved" AND payment_status = "unpaid"',
        );
        $unpaid->execute([$companyId]);
        $unpaidRow = $unpaid->fetch() ?: [];

        $draft = $this->db->prepare(
            'SELECT COUNT(*) FROM payroll_runs WHERE company_id = ? AND status = "draft"',
        );
        $draft->execute([$companyId]);

        $missingBank = $this->db->prepare(
            'SELECT COUNT(*) FROM employees e
             WHERE e.company_id = ? AND e.status = "active" AND NOT EXISTS (
                 SELECT 1 FROM employee_bank_accounts b
                 WHERE b.employee_id = e.id AND b.company_id = e.company_id
                   AND b.account_no IS NOT NULL AND b.account_no <> ""
             )',
        );
        $missingBank->execute([$companyId]);

        $pendingClaims = $this->db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM expense_claims WHERE company_id = ? AND status = "pending") +
                (SELECT COUNT(*) FROM loss_damage_claims WHERE company_id = ? AND status = "pending") +
                (SELECT COUNT(*) FROM advance_requests WHERE company_id = ? AND status = "pending") +
                (SELECT COUNT(*) FROM final_dues WHERE company_id = ? AND status = "draft") AS total',
        );
        $pendingClaims->execute([$companyId, $companyId, $companyId, $companyId]);

        $statutory = $latest === null ? $this->emptyStatutory() : $this->statutory((int) $latest['id']);
        $variance = ($latest !== null && $prior !== null && (int) $prior['employer_cost_cents'] > 0)
            ? (int) $latest['employer_cost_cents'] - (int) $prior['employer_cost_cents']
            : null;

        return [
            'latest' => $latest,
            'previous' => $prior,
            'draft_count' => (int) $draft->fetchColumn(),
            'unpaid_count' => (int) ($unpaidRow['count'] ?? 0),
            'unpaid_net_cents' => (int) ($unpaidRow['net_cents'] ?? 0),
            'unpaid_employer_cents' => (int) ($unpaidRow['employer_cents'] ?? 0),
            'missing_bank' => (int) $missingBank->fetchColumn(),
            'pending_claims' => (int) $pendingClaims->fetchColumn(),
            'statutory' => $statutory,
            'variance_cents' => $variance,
        ];
    }

    /** @return array{paye:int,nssf:int,shif:int,housing:int,nita:int,total:int} */
    public function statutory(int $runId): array
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(paye_cents), 0) AS paye,
                    COALESCE(SUM(nssf_employee_cents + nssf_employer_cents), 0) AS nssf,
                    COALESCE(SUM(shif_cents), 0) AS shif,
                    COALESCE(SUM(housing_levy_employee_cents + housing_levy_employer_cents), 0) AS housing,
                    COALESCE(SUM(nita_cents), 0) AS nita
             FROM payroll_lines WHERE run_id = ?',
        );
        $statement->execute([$runId]);
        $row = $statement->fetch() ?: [];

        $values = [
            'paye' => (int) ($row['paye'] ?? 0),
            'nssf' => (int) ($row['nssf'] ?? 0),
            'shif' => (int) ($row['shif'] ?? 0),
            'housing' => (int) ($row['housing'] ?? 0),
            'nita' => (int) ($row['nita'] ?? 0),
        ];
        $values['total'] = array_sum($values);

        return $values;
    }

    /** @return array{paye:int,nssf:int,shif:int,housing:int,nita:int,total:int} */
    public function emptyStatutory(): array
    {
        return ['paye' => 0, 'nssf' => 0, 'shif' => 0, 'housing' => 0, 'nita' => 0, 'total' => 0];
    }

    /** @return list<array<string,mixed>> */
    public function departmentCost(int $companyId, string $period): array
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(d.name, "Unassigned") AS department,
                    COUNT(l.id) AS employee_count,
                    COALESCE(SUM(l.gross_cents), 0) AS gross_cents,
                    COALESCE(SUM(l.deductions_cents), 0) AS deductions_cents,
                    COALESCE(SUM(l.net_cents), 0) AS net_cents,
                    COALESCE(SUM(l.employer_cost_cents), 0) AS employer_cost_cents
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id AND r.company_id = ?
             JOIN employees e ON e.id = l.employee_id AND e.company_id = r.company_id
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE r.period = ? AND r.status = "approved" AND r.run_type = "regular"
             GROUP BY d.id, d.name
             ORDER BY employer_cost_cents DESC',
        );
        $statement->execute([$companyId, $period]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function payrollTrend(int $companyId, int $limit = 12): array
    {
        $statement = $this->db->prepare(
            'SELECT period, label, gross_cents, deductions_cents, net_cents, employer_cost_cents, employee_count
             FROM payroll_runs
             WHERE company_id = ? AND status = "approved" AND run_type = "regular"
             ORDER BY period DESC, run_no DESC LIMIT ' . max(1, min(36, $limit)),
        );
        $statement->execute([$companyId]);

        return array_reverse($statement->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function leaveLiability(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name,
                    COALESCE(SUM(t.days), 0) AS days,
                    COALESCE(em.salary_cents, 0) AS monthly_salary_cents,
                    ROUND(COALESCE(SUM(t.days), 0) * COALESCE(em.salary_cents, 0) / 30 / 100, 2) AS liability
             FROM leave_batches b
             JOIN leave_transactions t ON t.batch_id = b.id AND t.company_id = b.company_id
             JOIN leave_types lt ON lt.id = b.leave_type_id
             JOIN employees e ON e.id = b.employee_id AND e.company_id = b.company_id
             LEFT JOIN employments em ON em.id = (
                 SELECT em2.id FROM employments em2
                 WHERE em2.employee_id = e.id AND em2.company_id = e.company_id
                 ORDER BY em2.start_date DESC, em2.id DESC LIMIT 1
             )
             WHERE b.company_id = ? AND lt.system_code = "annual" AND e.status = "active"
             GROUP BY e.id, e.employee_no, e.first_name, e.last_name, em.salary_cents
             HAVING days > 0
             ORDER BY liability DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function p9Rows(int $companyId, string $year): array
    {
        $statement = $this->db->prepare(
            'SELECT l.employee_no, l.employee_name, s.kra_pin,
                    SUM(l.gross_cents) AS gross_cents,
                    SUM(l.taxable_cents) AS taxable_cents,
                    SUM(l.paye_cents) AS paye_cents,
                    SUM(l.nssf_employee_cents) AS nssf_cents,
                    SUM(l.shif_cents) AS shif_cents,
                    SUM(l.housing_levy_employee_cents) AS housing_cents
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id AND r.company_id = ?
             JOIN employees e ON e.id = l.employee_id
             LEFT JOIN employee_statutory s ON s.employee_id = e.id AND s.company_id = e.company_id
             WHERE r.status = "approved" AND r.period LIKE ?
             GROUP BY l.employee_id, l.employee_no, l.employee_name, s.kra_pin
             ORDER BY l.employee_name',
        );
        $statement->execute([$companyId, $year . '-%']);

        return $statement->fetchAll();
    }

    /**
     * Default chart of accounts and mappings for a company. Runs on first
     * visit to a finance screen; never overwrites an account the customer
     * already configured.
     */
    public function ensureGlDefaults(int $companyId): void
    {
        $accounts = [
            ['5000', 'Salaries and wages', 'expense'],
            ['5010', 'Employer statutory contributions', 'expense'],
            ['5100', 'Staff reimbursements', 'expense'],
            ['2100', 'Net pay payable', 'liability'],
            ['2200', 'PAYE payable', 'liability'],
            ['2210', 'NSSF payable', 'liability'],
            ['2220', 'SHIF payable', 'liability'],
            ['2230', 'Housing Levy payable', 'liability'],
            ['2240', 'NITA payable', 'liability'],
            ['2250', 'Other staff deductions payable', 'liability'],
            ['1300', 'Staff loans and advances', 'asset'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            $this->db->prepare(
                'INSERT INTO gl_accounts (company_id, code, name, type)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)',
            )->execute([$companyId, $code, $name, $type]);
        }

        $mappings = [
            ['salary', '5000', '2100'],
            ['employer_statutory', '5010', '2210'],
            ['reimbursements', '5100', '2100'],
            ['net_pay', '2100', '2100'],
            ['paye', '2100', '2200'],
            ['nssf', '2100', '2210'],
            ['shif', '2100', '2220'],
            ['housing', '2100', '2230'],
            ['nita', '2100', '2240'],
            ['loans', '2100', '1300'],
        ];

        foreach ($mappings as [$key, $debit, $credit]) {
            $this->db->prepare(
                'INSERT INTO gl_mappings (company_id, pay_item_key, debit_account, credit_account)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE debit_account = VALUES(debit_account), credit_account = VALUES(credit_account)',
            )->execute([$companyId, $key, $debit, $credit]);
        }

        $reports = [
            ['payroll-summary', 'Payroll summary'],
            ['department-cost', 'Payroll cost by department'],
            ['variance', 'Payroll variance'],
            ['statutory', 'Statutory liability'],
            ['leave-liability', 'Leave liability'],
            ['loan-balances', 'Loan balances'],
            ['p9', 'P9 annual tax'],
            ['p10', 'P10 monthly PAYE'],
            ['gl-journal', 'General ledger journal'],
        ];

        foreach ($reports as [$key, $name]) {
            $this->db->prepare(
                'INSERT INTO report_definitions (company_id, `key`, name)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name)',
            )->execute([$companyId, $key, $name]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function loanReport(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, e.employee_no, e.first_name, e.last_name,
                    COALESCE(SUM(s.principal_cents + s.interest_cents - s.paid_cents), 0) AS balance_cents
             FROM loans l
             JOIN employees e ON e.id = l.employee_id AND e.company_id = l.company_id
             LEFT JOIN loan_schedule s ON s.loan_id = l.id AND s.company_id = l.company_id
             WHERE l.company_id = ? AND l.status = "active"
             GROUP BY l.id
             HAVING balance_cents > 0
             ORDER BY balance_cents DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function glAccounts(int $companyId): array
    {
        $statement = $this->db->prepare('SELECT * FROM gl_accounts WHERE company_id = ? ORDER BY code');
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function glMappings(int $companyId): array
    {
        $statement = $this->db->prepare('SELECT * FROM gl_mappings WHERE company_id = ? ORDER BY pay_item_key');
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }
}
