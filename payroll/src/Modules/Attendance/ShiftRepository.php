<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use PDO;

/**
 * Shift definitions and the roster: which shift, or rest day, each person is
 * expected to work. Actual clock times stay in attendance_records.
 */
final class ShiftRepository
{
    public const DEFAULTS = [
        ['Day', '08:00:00', '17:00:00', 60],
        ['Early', '06:30:00', '14:30:00', 30],
        ['Late', '14:00:00', '22:00:00', 30],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Safe to run more than once. */
    public function provisionDefaults(int $companyId): void
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO shifts (company_id, name, start_time, end_time, break_minutes)
             VALUES (?, ?, ?, ?, ?)',
        );

        foreach (self::DEFAULTS as [$name, $start, $end, $break]) {
            $statement->execute([$companyId, $name, $start, $end, $break]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId, bool $includeInactive = false): array
    {
        $sql = 'SELECT * FROM shifts WHERE company_id = ?';

        if (!$includeInactive) {
            $sql .= ' AND active = 1';
        }

        $statement = $this->db->prepare($sql . ' ORDER BY start_time, name');
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $shiftId, bool $includeInactive = false): ?array
    {
        $sql = 'SELECT * FROM shifts WHERE id = ? AND company_id = ?';

        if (!$includeInactive) {
            $sql .= ' AND active = 1';
        }

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute([$shiftId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function nameExists(int $companyId, string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM shifts WHERE company_id = ? AND name = ?';
        $params = [$companyId, $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $this->db
            ->prepare(
                'INSERT INTO shifts (company_id, name, start_time, end_time, break_minutes, active)
                 VALUES (?, ?, ?, ?, ?, ?)',
            )
            ->execute([
                $companyId,
                $data['name'],
                $data['start_time'],
                $data['end_time'],
                $data['break_minutes'],
                $data['active'],
            ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $shiftId, array $data): void
    {
        $this->db
            ->prepare(
                'UPDATE shifts
                 SET name = ?, start_time = ?, end_time = ?, break_minutes = ?, active = ?
                 WHERE id = ? AND company_id = ?',
            )
            ->execute([
                $data['name'],
                $data['start_time'],
                $data['end_time'],
                $data['break_minutes'],
                $data['active'],
                $shiftId,
                $companyId,
            ]);
    }

    public function setActive(int $companyId, int $shiftId, bool $active): void
    {
        $this->db
            ->prepare('UPDATE shifts SET active = ? WHERE id = ? AND company_id = ?')
            ->execute([$active ? 1 : 0, $shiftId, $companyId]);
    }

    /**
     * Roster rows for one person between two dates, keyed by Y-m-d.
     *
     * @return array<string,array<string,mixed>>
     */
    public function forEmployeeBetween(int $companyId, int $employeeId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT r.work_date, r.status AS roster_status, r.shift_id,
                    s.name AS shift_name, s.start_time, s.end_time, s.break_minutes
             FROM roster_entries r
             LEFT JOIN shifts s ON s.id = r.shift_id AND s.company_id = r.company_id
             WHERE r.company_id = ? AND r.employee_id = ?
               AND r.work_date BETWEEN ? AND ?
             ORDER BY r.work_date',
        );
        $statement->execute([$companyId, $employeeId, $from, $to]);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['work_date']] = $row;
        }

        return $rows;
    }

    public function upsert(
        int $companyId,
        int $employeeId,
        string $workDate,
        ?int $shiftId,
        string $status,
        int $actorId,
    ): void {
        $this->db
            ->prepare(
                'INSERT INTO roster_entries
                    (company_id, employee_id, work_date, shift_id, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    shift_id = VALUES(shift_id),
                    status = VALUES(status),
                    created_by = VALUES(created_by),
                    updated_at = UTC_TIMESTAMP()',
            )
            ->execute([$companyId, $employeeId, $workDate, $shiftId, $status, $actorId]);
    }
}
