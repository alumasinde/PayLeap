<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Employees;

use PDO;
use Throwable;

/**
 * SQL for the employee register. Every read and write is scoped by company,
 * so a record can never cross a tenant boundary.
 *
 * Personal identity lives on employees. Statutory numbers, the current
 * employment and the primary bank account live in their own tables. Reads
 * alias those columns back to the register names the rest of the app expects
 * (kra_pin, monthly_salary_cents, bank_name, ...).
 */
final class EmployeeRepository
{
    private const CORE = [
        'employee_no', 'first_name', 'middle_name', 'last_name', 'dob', 'gender',
        'national_id', 'phone', 'email', 'address', 'marital_status', 'nationality',
        'cost_center', 'photo_path',
        'department_id', 'manager_id', 'user_id', 'status', 'exit_date', 'archived_at',
    ];

    private const STATUTORY = [
        'kra_pin' => 'kra_pin',
        'nssf_number' => 'nssf_no',
        'shif_number' => 'sha_no',
    ];

    private const EMPLOYMENT = [
        'employment_type' => 'type',
        'pay_basis' => 'pay_basis',
        'start_date' => 'start_date',
        'exit_date' => 'end_date',
        'job_title' => 'job_title',
        'monthly_salary_cents' => 'salary_cents',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $core = $this->pick($data, self::CORE);
            $core['company_id'] = $companyId;
            $core['status'] ??= 'active';

            $columns = array_keys($core);
            $statement = $this->db->prepare(
                'INSERT INTO employees (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            );
            $statement->execute(array_values($core));

            $employeeId = (int) $this->db->lastInsertId();

            $this->writeEmployment($companyId, $employeeId, $data, $core, true);
            $this->writeStatutory($companyId, $employeeId, $data);
            $this->writeBank($companyId, $employeeId, $data);
            $this->writeNextOfKin($companyId, $employeeId, $data);

            if ($own) {
                $this->db->commit();
            }

            return $employeeId;
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $employeeId, array $data): void
    {
        $own = !$this->db->inTransaction();

        if ($own) {
            $this->db->beginTransaction();
        }

        try {
            $core = $this->pick($data, self::CORE);

            if ($core !== []) {
                $assignments = implode(', ', array_map(
                    static fn (string $column): string => $column . ' = ?',
                    array_keys($core),
                ));

                $values = array_values($core);
                $values[] = $employeeId;
                $values[] = $companyId;

                $this->db
                    ->prepare('UPDATE employees SET ' . $assignments . ' WHERE id = ? AND company_id = ?')
                    ->execute($values);
            }

            $this->writeEmployment($companyId, $employeeId, $data, $core, false);
            $this->writeStatutory($companyId, $employeeId, $data);
            $this->writeBank($companyId, $employeeId, $data);
            $this->writeNextOfKin($companyId, $employeeId, $data);

            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $error) {
            if ($own) {
                $this->db->rollBack();
            }

            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $employeeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . $this->personColumns() . ',
                    d.name AS department_name,
                    m.first_name AS manager_first_name, m.last_name AS manager_last_name
             FROM employees e
             ' . $this->personJoins() . '
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN employees m ON m.id = e.manager_id AND m.company_id = e.company_id
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
            'SELECT id, employee_no, first_name, last_name, department_id, manager_id
             FROM employees
             WHERE company_id = ? AND user_id = ? AND status = \'active\'
             LIMIT 1',
        );
        $statement->execute([$companyId, $userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function managerFor(int $companyId, int $employeeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT m.id, m.user_id, m.department_id, m.first_name, m.last_name
             FROM employees e
             JOIN employees m ON m.id = e.manager_id AND m.company_id = e.company_id
             WHERE e.id = ? AND e.company_id = ?
             LIMIT 1',
        );
        $statement->execute([$employeeId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function nextOfKin(int $companyId, int $employeeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM next_of_kin
             WHERE company_id = ? AND employee_id = ?
             ORDER BY is_emergency DESC, id
             LIMIT 1',
        );
        $statement->execute([$companyId, $employeeId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function dependants(int $companyId, int $employeeId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM dependants
             WHERE company_id = ? AND employee_id = ?
             ORDER BY name',
        );
        $statement->execute([$companyId, $employeeId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function education(int $companyId, int $employeeId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM employee_education
             WHERE company_id = ? AND employee_id = ?
             ORDER BY end_date IS NULL, end_date DESC, id DESC',
        );
        $statement->execute([$companyId, $employeeId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function directReports(int $companyId, int $managerId): array
    {
        $statement = $this->db->prepare(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name,
                    em.job_title, d.name AS department_name
             FROM employees e
             LEFT JOIN employments em ON em.id = (' . $this->currentEmploymentQuery() . ')
             LEFT JOIN departments d ON d.id = e.department_id
             WHERE e.company_id = ? AND e.manager_id = ? AND e.status = \'active\'
             ORDER BY e.last_name, e.first_name',
        );
        $statement->execute([$companyId, $managerId]);

        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function addEducation(int $companyId, int $employeeId, array $data): int
    {
        $data['company_id'] = $companyId;
        $data['employee_id'] = $employeeId;

        $columns = array_keys($data);
        $this->db
            ->prepare('INSERT INTO employee_education (' . implode(', ', $columns) . ')
                       VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    public function deleteEducation(int $companyId, int $employeeId, int $educationId): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM employee_education WHERE id = ? AND employee_id = ? AND company_id = ?',
        );
        $statement->execute([$educationId, $employeeId, $companyId]);

        return $statement->rowCount() > 0;
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

    /** @return list<string> */
    public function costCenters(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT cost_center FROM employees
             WHERE company_id = ? AND cost_center IS NOT NULL AND cost_center <> \'\'
             ORDER BY cost_center',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param list<int> $employeeIds */
    public function setCostCenter(int $companyId, array $employeeIds, ?string $costCenter): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            'UPDATE employees SET cost_center = ?
             WHERE company_id = ? AND id IN (' . $placeholders . ')',
        );
        $statement->execute([$costCenter, $companyId, ...$ids]);

        return $statement->rowCount();
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
    public function activeForSelect(int $companyId, ?int $exceptId = null): array
    {
        $sql = 'SELECT id, employee_no, first_name, last_name, department_id
                FROM employees
                WHERE company_id = ? AND status = \'active\'';
        $params = [$companyId];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $statement = $this->db->prepare($sql . ' ORDER BY last_name, first_name');
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * Active people an approver may see: direct reports, their department and
     * themselves. A company approver passes a null scope and sees everyone.
     *
     * @param array{all:bool,employee_id:?int,department_id:?int}|null $scope
     * @return list<array<string,mixed>>
     */
    public function activeForSelectInScope(int $companyId, ?array $scope): array
    {
        if ($scope === null || $scope['all']) {
            return $this->activeForSelect($companyId);
        }

        $clauses = [];
        $params = [$companyId];

        if ($scope['employee_id'] !== null) {
            $clauses[] = 'manager_id = ?';
            $params[] = $scope['employee_id'];
            $clauses[] = 'id = ?';
            $params[] = $scope['employee_id'];
        }

        if ($scope['department_id'] !== null) {
            $clauses[] = 'department_id = ?';
            $params[] = $scope['department_id'];
        }

        $sql = 'SELECT id, employee_no, first_name, last_name
                FROM employees
                WHERE company_id = ? AND status = \'active\''
            . ($clauses === [] ? ' AND 1 = 0' : ' AND (' . implode(' OR ', $clauses) . ')')
            . ' ORDER BY last_name, first_name';

        $statement = $this->db->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** Logins that belong to this company, for linking an employee record. */
    public function portalUsers(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.first_name, u.last_name, u.email
             FROM users u
             JOIN memberships m ON m.user_id = u.id AND m.company_id = ? AND m.status = \'active\'
             ORDER BY u.first_name, u.last_name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function userIsMember(int $companyId, int $userId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM memberships
             WHERE company_id = ? AND user_id = ? AND status = \'active\'
             LIMIT 1',
        );
        $statement->execute([$companyId, $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function userLinkedToOther(int $companyId, int $userId, ?int $exceptEmployeeId): bool
    {
        $sql = 'SELECT 1 FROM employees WHERE company_id = ? AND user_id = ?';
        $params = [$companyId, $userId];

        if ($exceptEmployeeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptEmployeeId;
        }

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array{status?:string,department_id?:int,search?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function search(int $companyId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->filter($companyId, $filters);

        $statement = $this->db->prepare(
            'SELECT ' . $this->personColumns() . ', d.name AS department_name
             FROM employees e
             ' . $this->personJoins() . '
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
                       COALESCE(SUM(e.status = \'active\'), 0) AS active,
                       COALESCE(SUM(e.status = \'exited\'), 0) AS exited';

        if ($includeSalary) {
            $sql .= ', COALESCE(SUM(CASE WHEN e.status = \'active\'
                                         THEN COALESCE(em.salary_cents, 0) ELSE 0 END), 0) AS monthly_cents';
        }

        $statement = $this->db->prepare(
            $sql . ' FROM employees e
                     LEFT JOIN employments em ON em.id = (' . $this->currentEmploymentQuery() . ')
                     WHERE e.company_id = ?',
        );
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
     * People paid in the period: active staff plus anyone who left during the
     * month, so a leaver is not silently dropped from their final payroll. The
     * caller prorates the salary and any allowances by the dates returned here.
     *
     * @return list<array<string,mixed>>
     */
    public function forPayroll(int $companyId, string $periodStart, string $periodEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name,
                    em.id AS employment_id, em.salary_cents AS monthly_salary_cents, em.start_date,
                    COALESCE(em.end_date, e.exit_date) AS employment_end_date,
                    COALESCE(em.pay_basis, \'month\') AS pay_basis
             FROM employees e
             JOIN employments em ON em.id = (' . $this->currentEmploymentQuery() . ')
             WHERE e.company_id = ? AND e.status IN (\'active\', \'exited\') AND em.salary_cents IS NOT NULL
               AND em.start_date <= ?
               AND (COALESCE(em.end_date, e.exit_date) IS NULL OR COALESCE(em.end_date, e.exit_date) >= ?)
             ORDER BY e.last_name, e.first_name',
        );
        $statement->execute([$companyId, $periodEnd, $periodStart]);

        return $statement->fetchAll();
    }

    /**
     * @param array{status?:string,department_id?:int,search?:string,attention?:string} $filters
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

        $attention = (string) ($filters['attention'] ?? '');

        if ($attention === 'no_manager') {
            $where .= ' AND e.manager_id IS NULL';
        } elseif ($attention === 'no_department') {
            $where .= ' AND e.department_id IS NULL';
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $where .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_no LIKE ?
                             OR e.national_id LIKE ? OR EXISTS (
                                 SELECT 1 FROM employee_statutory s
                                 WHERE s.employee_id = e.id AND s.kra_pin LIKE ?
                             ))';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [$where, $params];
    }

    /** @param array<string,mixed> $data
     *  @param array<string,mixed> $core
     */
    private function writeEmployment(int $companyId, int $employeeId, array $data, array $core, bool $creating): void
    {
        $fields = [];

        foreach (self::EMPLOYMENT as $input => $column) {
            if (array_key_exists($input, $data)) {
                $fields[$column] = $data[$input];
            }
        }

        if (!$creating && $fields === [] && !array_key_exists('department_id', $core) && !array_key_exists('manager_id', $core)) {
            return;
        }

        $mirror = [];

        if (array_key_exists('department_id', $core)) {
            $mirror['dept_id'] = $core['department_id'];
        }

        if (array_key_exists('manager_id', $core)) {
            $mirror['manager_id'] = $core['manager_id'];
        }

        if ($creating) {
            $fields += $mirror;
            $fields['type'] ??= 'permanent';

            if (!isset($fields['start_date'])) {
                $fields['start_date'] = date('Y-m-d');
            }

            $fields['company_id'] = $companyId;
            $fields['employee_id'] = $employeeId;

            $columns = array_keys($fields);
            $this->db
                ->prepare('INSERT INTO employments (' . implode(', ', $columns) . ')
                           VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                ->execute(array_values($fields));

            $this->writePayRate(
                $companyId,
                (int) $this->db->lastInsertId(),
                $fields['salary_cents'] ?? null,
                $fields['pay_basis'] ?? null,
                (string) $fields['start_date'],
            );

            return;
        }

        $statement = $this->db->prepare(
            'SELECT id FROM employments
             WHERE employee_id = ? AND company_id = ?
             ORDER BY start_date DESC, id DESC
             LIMIT 1',
        );
        $statement->execute([$employeeId, $companyId]);
        $employmentId = $statement->fetchColumn();

        if ($employmentId === false) {
            $fields += $mirror;
            $fields['company_id'] = $companyId;
            $fields['employee_id'] = $employeeId;
            $fields['type'] ??= 'permanent';
            $fields['start_date'] ??= date('Y-m-d');

            $columns = array_keys($fields);
            $this->db
                ->prepare('INSERT INTO employments (' . implode(', ', $columns) . ')
                           VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                ->execute(array_values($fields));

            $this->writePayRate(
                $companyId,
                (int) $this->db->lastInsertId(),
                $fields['salary_cents'] ?? null,
                $fields['pay_basis'] ?? null,
                (string) $fields['start_date'],
            );

            return;
        }

        $fields += $mirror;

        if ($fields === []) {
            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($fields),
        ));

        $values = array_values($fields);
        $values[] = (int) $employmentId;
        $values[] = $employeeId;
        $values[] = $companyId;

        $before = $this->db->prepare('SELECT salary_cents, pay_basis, start_date FROM employments WHERE id = ? AND company_id = ? LIMIT 1');
        $before->execute([(int) $employmentId, $companyId]);
        $current = $before->fetch();

        $this->db
            ->prepare('UPDATE employments SET ' . $assignments . '
                       WHERE id = ? AND employee_id = ? AND company_id = ?')
            ->execute($values);

        $newSalary = $fields['salary_cents'] ?? ($current === false ? null : $current['salary_cents']);
        $newBasis = $fields['pay_basis'] ?? ($current === false ? null : $current['pay_basis']);
        $oldSalary = $current === false ? null : $current['salary_cents'];
        $oldBasis = $current === false ? null : $current['pay_basis'];

        if ((string) $newSalary !== (string) $oldSalary || (string) $newBasis !== (string) $oldBasis) {
            $this->writePayRate(
                $companyId,
                (int) $employmentId,
                $newSalary,
                $newBasis,
                (string) ($data['salary_effective_date'] ?? date('Y-m-d')),
            );
        }
    }

    private function writePayRate(
        int $companyId,
        int $employmentId,
        mixed $salaryCents,
        mixed $payBasis,
        string $effectiveFrom,
    ): void {
        if ($salaryCents === null || $salaryCents === '' || !is_numeric($salaryCents)) {
            return;
        }

        $this->db->prepare(
            'INSERT INTO employment_pay_rates (company_id, employment_id, salary_cents, pay_basis, effective_from)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE salary_cents = VALUES(salary_cents), pay_basis = VALUES(pay_basis)',
        )->execute([
            $companyId,
            $employmentId,
            (int) $salaryCents,
            (string) ($payBasis ?: 'monthly'),
            $effectiveFrom,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function payRates(int $companyId, int $employmentId, string $periodEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT salary_cents, pay_basis, effective_from
             FROM employment_pay_rates
             WHERE company_id = ? AND employment_id = ? AND effective_from <= ?
             ORDER BY effective_from, id',
        );
        $statement->execute([$companyId, $employmentId, $periodEnd]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function payRateHistory(int $companyId, int $employeeId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.salary_cents, r.pay_basis, r.effective_from, r.created_at
             FROM employment_pay_rates r
             JOIN employments em ON em.id = r.employment_id AND em.company_id = r.company_id
             WHERE r.company_id = ? AND em.employee_id = ?
             ORDER BY r.effective_from DESC, r.id DESC',
        );
        $statement->execute([$companyId, $employeeId]);

        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    private function writeStatutory(int $companyId, int $employeeId, array $data): void
    {
        $fields = [];

        foreach (self::STATUTORY as $input => $column) {
            if (array_key_exists($input, $data)) {
                $fields[$column] = $data[$input];
            }
        }

        if ($fields === []) {
            return;
        }

        $columns = array_keys($fields);
        $updates = implode(', ', array_map(
            static fn (string $column): string => $column . ' = VALUES(' . $column . ')',
            $columns,
        ));

        $statement = $this->db->prepare(
            'INSERT INTO employee_statutory (company_id, employee_id, ' . implode(', ', $columns) . ')
             VALUES (?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ')
             ON DUPLICATE KEY UPDATE ' . $updates,
        );

        $statement->execute([$companyId, $employeeId, ...array_values($fields)]);
    }

    /** @param array<string,mixed> $data */
    private function writeBank(int $companyId, int $employeeId, array $data): void
    {
        $wantsBank = array_key_exists('bank_name', $data);
        $wantsBranch = array_key_exists('bank_branch', $data);
        $wantsAccount = array_key_exists('bank_account', $data);
        $wantsMpesa = array_key_exists('mpesa_no', $data);

        if (!$wantsBank && !$wantsBranch && !$wantsAccount && !$wantsMpesa) {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id, bank_id FROM employee_bank_accounts
             WHERE employee_id = ? AND company_id = ? AND is_primary = 1
             ORDER BY id
             LIMIT 1',
        );
        $statement->execute([$employeeId, $companyId]);
        $account = $statement->fetch();
        $accountId = $account === false ? null : (int) $account['id'];
        $bankId = $account === false || $account['bank_id'] === null ? null : (int) $account['bank_id'];

        $fields = [];
        $bankChanged = false;

        if ($wantsBank) {
            $name = trim((string) $data['bank_name']);
            $newBankId = $name === '' ? null : $this->bankId($name);
            $bankChanged = $newBankId !== $bankId;
            $bankId = $newBankId;
            $fields['bank_id'] = $bankId;
        }

        if ($wantsBranch) {
            $branch = trim((string) $data['bank_branch']);
            $fields['branch_id'] = $branch === '' || $bankId === null
                ? null
                : $this->branchId($bankId, $branch);
        } elseif ($bankChanged) {
            $fields['branch_id'] = null;
        }

        if ($wantsAccount) {
            $accountNo = trim((string) $data['bank_account']);
            $fields['account_no'] = $accountNo === '' ? null : $accountNo;
        }

        if ($wantsMpesa) {
            $mpesa = trim((string) $data['mpesa_no']);
            $fields['mpesa_no'] = $mpesa === '' ? null : $mpesa;
        }

        if ($accountId === null) {
            $fields['company_id'] = $companyId;
            $fields['employee_id'] = $employeeId;
            $fields['is_primary'] = 1;

            $columns = array_keys($fields);
            $this->db
                ->prepare('INSERT INTO employee_bank_accounts (' . implode(', ', $columns) . ')
                           VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                ->execute(array_values($fields));

            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($fields),
        ));
        $values = array_values($fields);
        $values[] = $accountId;
        $values[] = $employeeId;
        $values[] = $companyId;

        $this->db
            ->prepare('UPDATE employee_bank_accounts SET ' . $assignments . '
                       WHERE id = ? AND employee_id = ? AND company_id = ?')
            ->execute($values);
    }

    /** @param array<string,mixed> $data */
    private function writeNextOfKin(int $companyId, int $employeeId, array $data): void
    {
        if (!array_key_exists('next_of_kin', $data) || !is_array($data['next_of_kin'])) {
            return;
        }

        $allowed = ['name', 'relationship', 'phone', 'email', 'address'];
        $fields = array_intersect_key($data['next_of_kin'], array_flip($allowed));

        $statement = $this->db->prepare(
            'SELECT id FROM next_of_kin
             WHERE company_id = ? AND employee_id = ? AND is_emergency = 1
             ORDER BY id
             LIMIT 1',
        );
        $statement->execute([$companyId, $employeeId]);
        $existingId = $statement->fetchColumn();

        $empty = true;

        foreach ($fields as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $empty = false;
                break;
            }
        }

        if ($empty) {
            if ($existingId !== false) {
                $this->db
                    ->prepare('DELETE FROM next_of_kin WHERE id = ? AND company_id = ? AND employee_id = ?')
                    ->execute([(int) $existingId, $companyId, $employeeId]);
            }

            return;
        }

        if ($existingId === false) {
            $fields['company_id'] = $companyId;
            $fields['employee_id'] = $employeeId;
            $fields['is_emergency'] = 1;

            $columns = array_keys($fields);
            $this->db
                ->prepare('INSERT INTO next_of_kin (' . implode(', ', $columns) . ')
                           VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                ->execute(array_values($fields));

            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => $column . ' = ?',
            array_keys($fields),
        ));
        $values = array_values($fields);
        $values[] = (int) $existingId;

        $this->db
            ->prepare('UPDATE next_of_kin SET ' . $assignments . ' WHERE id = ?')
            ->execute($values);
    }

    private function bankId(string $name): int
    {
        $this->db->prepare('INSERT IGNORE INTO bank_names (bank_name) VALUES (?)')->execute([$name]);

        $statement = $this->db->prepare('SELECT id FROM bank_names WHERE bank_name = ? LIMIT 1');
        $statement->execute([$name]);

        return (int) $statement->fetchColumn();
    }

    private function branchId(int $bankId, string $branch): int
    {
        $this->db
            ->prepare('INSERT IGNORE INTO bank_branches (bank_id, branch) VALUES (?, ?)')
            ->execute([$bankId, $branch]);

        $statement = $this->db->prepare(
            'SELECT id FROM bank_branches WHERE bank_id = ? AND branch = ? LIMIT 1',
        );
        $statement->execute([$bankId, $branch]);

        return (int) $statement->fetchColumn();
    }

    private function personColumns(): string
    {
        return 'e.*,
                s.kra_pin, s.nssf_no AS nssf_number, s.sha_no AS shif_number,
                s.is_resident, s.has_disability_exemption, s.is_primary_employee,
                em.type AS employment_type, em.start_date, em.end_date AS employment_end_date,
                em.pay_basis, em.salary_cents AS monthly_salary_cents, em.job_title, em.probation_end,
                bn.bank_name AS bank_name, bb.branch AS bank_branch, b.account_no AS bank_account, b.mpesa_no';
    }

    private function personJoins(): string
    {
        return 'LEFT JOIN employee_statutory s ON s.employee_id = e.id AND s.company_id = e.company_id
                LEFT JOIN employments em ON em.id = (' . $this->currentEmploymentQuery() . ')
                LEFT JOIN employee_bank_accounts b ON b.id = (
                    SELECT b2.id FROM employee_bank_accounts b2
                    WHERE b2.employee_id = e.id AND b2.company_id = e.company_id AND b2.is_primary = 1
                    ORDER BY b2.id
                    LIMIT 1
                )
                LEFT JOIN bank_names bn ON bn.id = b.bank_id
                LEFT JOIN bank_branches bb ON bb.id = b.branch_id';
    }

    private function currentEmploymentQuery(): string
    {
        return 'SELECT em2.id FROM employments em2
                WHERE em2.employee_id = e.id AND em2.company_id = e.company_id
                ORDER BY em2.start_date DESC, em2.id DESC
                LIMIT 1';
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $allowed
     * @return array<string,mixed>
     */
    private function pick(array $data, array $allowed): array
    {
        $picked = [];

        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $picked[$column] = $data[$column];
            }
        }

        return $picked;
    }
}
