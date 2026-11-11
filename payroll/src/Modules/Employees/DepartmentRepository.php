<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Employees;

use PDO;

/**
 * Departments belong to a company and are used to group the register now and
 * payroll cost reports later.
 */
final class DepartmentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT d.id, d.name, COUNT(e.id) AS employee_count
             FROM departments d
             LEFT JOIN employees e ON e.department_id = d.id AND e.status = \'active\'
             WHERE d.company_id = ?
             GROUP BY d.id, d.name
             ORDER BY d.name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $departmentId): ?array
    {
        $statement = $this->db->prepare('SELECT id, name FROM departments WHERE id = ? AND company_id = ? LIMIT 1');
        $statement->execute([$departmentId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function exists(int $companyId, string $name): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM departments WHERE company_id = ? AND name = ? LIMIT 1');
        $statement->execute([$companyId, $name]);

        return $statement->fetchColumn() !== false;
    }

    public function create(int $companyId, string $name): int
    {
        $this->db
            ->prepare('INSERT INTO departments (company_id, name) VALUES (?, ?)')
            ->execute([$companyId, $name]);

        return (int) $this->db->lastInsertId();
    }
}
