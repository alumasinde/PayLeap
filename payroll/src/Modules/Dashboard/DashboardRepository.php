<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Dashboard;

use PDO;

/**
 * Read-only aggregates for the dashboard. Every query is scoped by company;
 * salary figures are only selected when the caller may see them.
 */
final class DashboardRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{total:int,active:int,exited:int,new_this_month:int,monthly_cents:int} */
    public function headcount(int $companyId, string $monthStart, bool $includeSalary): array
    {
        $sql = 'SELECT COUNT(*) AS total,
                       COALESCE(SUM(status = \'active\'), 0) AS active,
                       COALESCE(SUM(status = \'exited\'), 0) AS exited,
                       COALESCE(SUM(status = \'active\' AND start_date >= ?), 0) AS new_this_month';

        if ($includeSalary) {
            $sql .= ', COALESCE(SUM(CASE WHEN status = \'active\'
                                          THEN COALESCE(monthly_salary_cents, 0) ELSE 0 END), 0) AS monthly_cents';
        }

        $statement = $this->db->prepare($sql . ' FROM employees WHERE company_id = ?');
        $statement->execute([$monthStart, $companyId]);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'exited' => (int) ($row['exited'] ?? 0),
            'new_this_month' => (int) ($row['new_this_month'] ?? 0),
            'monthly_cents' => (int) ($row['monthly_cents'] ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function departments(int $companyId, int $limit = 6): array
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(d.name, \'Unassigned\') AS name, COUNT(e.id) AS employee_count
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.company_id = ? AND e.status = \'active\'
             GROUP BY d.id, d.name
             ORDER BY employee_count DESC, name
             LIMIT ' . max(1, min(20, $limit)),
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function recentEmployees(int $companyId, int $limit = 5): array
    {
        $statement = $this->db->prepare(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name, e.job_title,
                    e.employment_type, e.start_date, e.created_at, d.name AS department_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.company_id = ? AND e.status = \'active\'
             ORDER BY e.start_date DESC, e.id DESC
             LIMIT ' . max(1, min(20, $limit)),
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function latestRun(int $companyId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, rv.label AS rate_label
             FROM payroll_runs r
             JOIN rate_versions rv ON rv.id = r.rate_version_id
             WHERE r.company_id = ?
             ORDER BY r.period DESC, r.id DESC
             LIMIT 1',
        );
        $statement->execute([$companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array{paye:int,nssf:int,shif:int,housing:int} */
    public function statutoryForRun(int $runId): array
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(paye_cents), 0) AS paye,
                    COALESCE(SUM(nssf_cents), 0) AS nssf,
                    COALESCE(SUM(shif_cents), 0) AS shif,
                    COALESCE(SUM(housing_levy_cents), 0) AS housing
             FROM payroll_lines
             WHERE run_id = ?',
        );
        $statement->execute([$runId]);
        $row = $statement->fetch() ?: [];

        return [
            'paye' => (int) ($row['paye'] ?? 0),
            'nssf' => (int) ($row['nssf'] ?? 0),
            'shif' => (int) ($row['shif'] ?? 0),
            'housing' => (int) ($row['housing'] ?? 0),
        ];
    }

    /** @return array{present:int,late:int,absent:int,marked:int} */
    public function attendanceOn(int $companyId, string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(a.status = \'present\'), 0) AS present,
                    COALESCE(SUM(a.status = \'late\'), 0) AS late_count,
                    COALESCE(SUM(a.status = \'absent\'), 0) AS absent,
                    COUNT(a.id) AS marked
             FROM attendance_records a
             JOIN employees e ON e.id = a.employee_id AND e.company_id = a.company_id
             WHERE a.company_id = ? AND a.work_date = ? AND e.status = \'active\'',
        );
        $statement->execute([$companyId, $date]);
        $row = $statement->fetch() ?: [];

        return [
            'present' => (int) ($row['present'] ?? 0),
            'late' => (int) ($row['late_count'] ?? 0),
            'absent' => (int) ($row['absent'] ?? 0),
            'marked' => (int) ($row['marked'] ?? 0),
        ];
    }

    public function onLeaveOn(int $companyId, string $date): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(DISTINCT lr.employee_id)
             FROM leave_requests lr
             JOIN employees e ON e.id = lr.employee_id AND e.company_id = lr.company_id
             WHERE lr.company_id = ? AND lr.status = \'approved\'
               AND lr.start_date <= ? AND lr.end_date >= ?
               AND e.status = \'active\'',
        );
        $statement->execute([$companyId, $date, $date]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{all?:bool,employee_id?:?int,department_id?:?int}|null $scope
     * @return array{0:string,1:list<mixed>}
     */
    private function leaveScopeClause(?array $scope): array
    {
        if ($scope === null || ($scope['all'] ?? false)) {
            return ['', []];
        }

        $clauses = [];
        $params = [];

        if (($scope['employee_id'] ?? null) !== null) {
            $clauses[] = 'e.manager_id = ?';
            $params[] = $scope['employee_id'];
        }

        if (($scope['department_id'] ?? null) !== null) {
            $clauses[] = 'e.department_id = ?';
            $params[] = $scope['department_id'];
        }

        return [
            $clauses === [] ? ' AND 1 = 0' : ' AND (' . implode(' OR ', $clauses) . ')',
            $params,
        ];
    }

    /**
     * @param array{all?:bool,employee_id?:?int,department_id?:?int}|null $scope
     */
    public function pendingLeaveCount(int $companyId, ?array $scope = null): int
    {
        $sql = 'SELECT COUNT(*)
                FROM leave_requests lr
                JOIN employees e ON e.id = lr.employee_id AND e.company_id = lr.company_id
                WHERE lr.company_id = ? AND lr.status = \'pending\'';
        $params = [$companyId];

        [$scopeSql, $scopeParams] = $this->leaveScopeClause($scope);
        $sql .= $scopeSql;
        array_push($params, ...$scopeParams);

        if (($scope['employee_id'] ?? null) !== null) {
            $sql .= ' AND lr.employee_id <> ?';
            $params[] = $scope['employee_id'];
        }

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{all?:bool,employee_id?:?int,department_id?:?int}|null $scope
     * @return list<array<string,mixed>>
     */
    public function pendingLeaveList(int $companyId, int $limit = 4, ?array $scope = null): array
    {
        $sql = 'SELECT lr.id, lr.start_date, lr.end_date, lr.days, lr.created_at,
                    e.first_name, e.last_name, e.employee_no,
                    lt.name AS leave_type
             FROM leave_requests lr
             JOIN employees e ON e.id = lr.employee_id AND e.company_id = lr.company_id
             JOIN leave_types lt ON lt.id = lr.leave_type_id AND lt.company_id = lr.company_id
             WHERE lr.company_id = ? AND lr.status = \'pending\'';
        $params = [$companyId];

        [$scopeSql, $scopeParams] = $this->leaveScopeClause($scope);
        $sql .= $scopeSql;
        array_push($params, ...$scopeParams);

        if (($scope['employee_id'] ?? null) !== null) {
            $sql .= ' AND lr.employee_id <> ?';
            $params[] = $scope['employee_id'];
        }

        $statement = $this->db->prepare($sql . ' ORDER BY lr.start_date, lr.id LIMIT ' . max(1, min(20, $limit)));
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function draftRunCount(int $companyId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM payroll_runs WHERE company_id = ? AND status = \'draft\'',
        );
        $statement->execute([$companyId]);

        return (int) $statement->fetchColumn();
    }

    public function pendingApprovalCount(
        int $companyId,
        bool $includeLeave,
        bool $includePayroll,
        ?array $leaveScope = null,
    ): int {
        $count = 0;

        if ($includeLeave) {
            $count += $this->pendingLeaveCount($companyId, $leaveScope);
        }

        if ($includePayroll) {
            $count += $this->draftRunCount($companyId);
        }

        return $count;
    }
}
