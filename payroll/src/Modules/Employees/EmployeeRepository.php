<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Employees;

use PDO;

/**
 * SQL for the employee register. Every read and write is scoped by company,
 * so a record can never cross a tenant boundary.
 */
final class EmployeeRepository
{
    /** Columns a caller may write. Status changes go through the service. */
    private const WRITABLE = [
        'employee_no', 'first_name', 'middle_name', 'last_name', 'gender', 'email', 'phone',
        'national_id', 'kra_pin', 'nssf_number', 'shif_number',
        'bank_name', 'bank_branch', 'bank_account',
        'department_id', 'job_title', 'employment_type',
        'monthly_salary_cents', 'start_date', 'exit_date', 'status',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $data['company_id'] = $companyId;
        $columns = array_values(array_intersect(array_keys($data), self::WRITABLE));
        $columns[] = 'company_id';

        $statement = $this->db->prepare(
            'INSERT INTO employees (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
        );
        $statement->execute(array_map(static fn (string $column): mixed => $data[$column], $columns));

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $employeeId, array $data): void
    {
        $columns = array_values(array_intersect(array_keys($data), self::WRITABLE));

        if ($columns === []) {
            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            $columns,
        ));

        $values = array_map(static fn (string $column): mixed => $data[$column], $columns);
        $values[] = $employeeId;
        $values[] = $companyId;

        $this->db
            ->prepare('UPDATE employees SET ' . $assignments . ' WHERE id = ? AND company_id = ?')
            ->execute($values);
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $employeeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT e.*, d.name AS department_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.id = ? AND e.company_id = ?
             LIMIT 1',
        );
        $statement->execute([$employeeId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The employee record linked to a login, if HR has linked one.
     *
     * @return array<string,mixed>|null
     */
    public function forUser(int $companyId, int $userId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, employee_no, first_name, last_name
             FROM employees
             WHERE company_id = ? AND user_id = ?
             LIMIT 1',
        );
        $statement->execute([$companyId, $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function exists(int $companyId, string $employeeNo, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM employees WHERE company_id = ? AND employee_no = ?';
        $params = [$companyId, $employeeNo];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** Next free EMP-nnnn number for a company. */
    public function nextNumber(int $companyId): string
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM employees WHERE company_id = ?');
        $statement->execute([$companyId]);

        $number = (int) $statement->fetchColumn();
        $attempt = 0;

        do {
            $number++;
            $attempt++;
            $candidate = sprintf('EMP-%04d', $number);
        } while ($this->exists($companyId, $candidate) && $attempt < 1000);

        return $candidate;
    }

    /**
     * Minimal active list for pickers (attendance, leave, payments).
     *
     * @return list<array<string,mixed>>
     */
    public function activeForSelect(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, employee_no, first_name, last_name
             FROM employees
             WHERE company_id = ? AND status = \'active\'
             ORDER BY last_name, first_name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /**
     * @param array{status?:string,department_id?:int,search?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function search(int $companyId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filter($companyId, $filters);

        $statement = $this->db->prepare(
            'SELECT e.*, d.name AS department_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id'
            . $where .
            ' ORDER BY e.last_name, e.first_name
              LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @param array{status?:string,department_id?:int,search?:string} $filters */
    public function count(int $companyId, array $filters): int
    {
        [$where, $params] = $this->filter($companyId, $filters);

        $statement = $this->db->prepare('SELECT COUNT(*) FROM employees e' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array{total:int,active:int,exited:int,monthly_cents:int} */
    public function summary(int $companyId, bool $includeSalary): array
    {
        $sql = 'SELECT COUNT(*) AS total,
                       COALESCE(SUM(status = \'active\'), 0) AS active,
                       COALESCE(SUM(status = \'exited\'), 0) AS exited';

        if ($includeSalary) {
            $sql .= ', COALESCE(SUM(CASE WHEN status = \'active\'
                                         THEN COALESCE(monthly_salary_cents, 0) ELSE 0 END), 0) AS monthly_cents';
        }

        $statement = $this->db->prepare($sql . ' FROM employees WHERE company_id = ?');
        $statement->execute([$companyId]);
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'exited' => (int) ($row['exited'] ?? 0),
            'monthly_cents' => (int) ($row['monthly_cents'] ?? 0),
        ];
    }

    /**
     * Active people with a salary who had started by the end of the period.
     *
     * @return list<array<string,mixed>>
     */
    public function forPayroll(int $companyId, string $periodEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT id, employee_no, first_name, last_name, monthly_salary_cents
             FROM employees
             WHERE company_id = ? AND status = ? AND monthly_salary_cents IS NOT NULL
               AND start_date <= ?
             ORDER BY last_name, first_name',
        );
        $statement->execute([$companyId, 'active', $periodEnd]);

        return $statement->fetchAll();
    }

    /**
     * @param array{status?:string,department_id?:int,search?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function filter(int $companyId, array $filters): array
    {
        $where = ' WHERE e.company_id = ?';
        $params = [$companyId];

        $status = (string) ($filters['status'] ?? 'active');

        if ($status !== 'all') {
            $where .= ' AND e.status = ?';
            $params[] = $status;
        }

        $departmentId = (int) ($filters['department_id'] ?? 0);

        if ($departmentId > 0) {
            $where .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $where .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_no LIKE ?
                             OR e.national_id LIKE ? OR e.kra_pin LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [$where, $params];
    }
}
