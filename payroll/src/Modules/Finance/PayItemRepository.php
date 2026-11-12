<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;

/**
 * Pay items are the company's catalogue of earnings, benefits and deductions.
 * An employee assignment gives one person a fixed amount or a percentage of
 * their base salary for a date range.
 */
final class PayItemRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId, bool $activeOnly = false): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM pay_items
             WHERE company_id = ?' . ($activeOnly ? ' AND active = 1' : '') . '
             ORDER BY sort_order, kind, name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function find(int $companyId, int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM pay_items WHERE id = ? AND company_id = ? LIMIT 1');
        $statement->execute([$id, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findByCode(int $companyId, string $code): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM pay_items WHERE company_id = ? AND code = ? LIMIT 1');
        $statement->execute([$companyId, $code]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO pay_items
                (company_id, code, name, kind, taxable, pensionable, cash, calc_type, active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $companyId,
            $data['code'] === '' ? null : $data['code'],
            $data['name'],
            $data['kind'],
            $data['taxable'],
            $data['pensionable'],
            $data['cash'],
            $data['calc_type'],
            $data['active'],
            $data['sort_order'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $id, array $data): void
    {
        $this->db
            ->prepare('UPDATE pay_items
                       SET code = ?, name = ?, kind = ?, taxable = ?, pensionable = ?, cash = ?,
                           calc_type = ?, active = ?, sort_order = ?
                       WHERE id = ? AND company_id = ?')
            ->execute([
                $data['code'] === '' ? null : $data['code'],
                $data['name'],
                $data['kind'],
                $data['taxable'],
                $data['pensionable'],
                $data['cash'],
                $data['calc_type'],
                $data['active'],
                $data['sort_order'],
                $id,
                $companyId,
            ]);
    }

    public function toggle(int $companyId, int $id): void
    {
        $this->db->prepare('UPDATE pay_items SET active = 1 - active WHERE id = ? AND company_id = ?')
            ->execute([$id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function assignments(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT a.*, i.name AS item_name, i.code AS item_code, i.kind, i.calc_type,
                    e.employee_no, e.first_name, e.last_name
             FROM employee_pay_items a
             JOIN pay_items i ON i.id = a.pay_item_id AND i.company_id = a.company_id
             JOIN employees e ON e.id = a.employee_id AND e.company_id = a.company_id
             WHERE a.company_id = ?
             ORDER BY e.last_name, e.first_name, i.sort_order, i.name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public function assign(int $companyId, array $data): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO employee_pay_items
                (company_id, employee_id, pay_item_id, amount_cents, percent, start_date, end_date)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $companyId,
            $data['employee_id'],
            $data['pay_item_id'],
            $data['amount_cents'],
            $data['percent'],
            $data['start_date'],
            $data['end_date'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function hasAssignment(
        int $companyId,
        int $employeeId,
        int $payItemId,
        ?string $startDate,
        ?string $endDate,
    ): bool {
        $statement = $this->db->prepare(
            'SELECT 1 FROM employee_pay_items
             WHERE company_id = ? AND employee_id = ? AND pay_item_id = ?
               AND (start_date IS NULL OR ? IS NULL OR start_date <= ?)
               AND (end_date IS NULL OR ? IS NULL OR end_date >= ?)
             LIMIT 1',
        );
        $statement->execute([
            $companyId,
            $employeeId,
            $payItemId,
            $endDate,
            $endDate,
            $startDate,
            $startDate,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function removeAssignment(int $companyId, int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM employee_pay_items WHERE id = ? AND company_id = ?');
        $statement->execute([$id, $companyId]);

        return $statement->rowCount() === 1;
    }

    /**
     * Active assignments for one employee whose date range overlaps the period.
     *
     * @return list<array<string,mixed>>
     */
    public function forEmployee(int $companyId, int $employeeId, string $periodStart, string $periodEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT a.*, i.code, i.name, i.kind, i.taxable, i.pensionable, i.cash, i.calc_type, i.sort_order
             FROM employee_pay_items a
             JOIN pay_items i ON i.id = a.pay_item_id AND i.company_id = a.company_id
             WHERE a.company_id = ? AND a.employee_id = ? AND i.active = 1
               AND (a.start_date IS NULL OR a.start_date <= ?)
               AND (a.end_date IS NULL OR a.end_date >= ?)
             ORDER BY i.sort_order, i.name, a.id',
        );
        $statement->execute([$companyId, $employeeId, $periodEnd, $periodStart]);

        return $statement->fetchAll();
    }
}
