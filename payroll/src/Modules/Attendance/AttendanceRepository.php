<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use PDO;

/**
 * One attendance row per person per day. The upsert key is (employee_id,
 * work_date), so re-marking a day corrects it rather than duplicating it.
 */
final class AttendanceRepository
{
    public const STATUSES = [
        'present' => 'Present',
        'late' => 'Late',
        'absent' => 'Absent',
        'leave' => 'On leave',
        'holiday' => 'Public holiday',
        'off' => 'Rest day',
    ];

    /** Where the clock times came from. Terminals post as biometric or face. */
    public const SOURCES = [
        'manual' => 'Manual entry',
        'biometric' => 'Biometric reader',
        'face' => 'Face scanner',
        'import' => 'CSV import',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function mark(
        int $companyId,
        int $employeeId,
        string $workDate,
        string $status,
        ?string $clockIn,
        ?string $clockOut,
        int $actorId,
        ?string $note = null,
        string $source = 'manual',
    ): void {
        $this->db
            ->prepare(
                'INSERT INTO attendance_records
                    (company_id, employee_id, work_date, clock_in, clock_out, status, source, note, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    clock_in = VALUES(clock_in),
                    clock_out = VALUES(clock_out),
                    status = VALUES(status),
                    source = VALUES(source),
                    note = VALUES(note),
                    recorded_by = VALUES(recorded_by),
                    updated_at = UTC_TIMESTAMP()',
            )
            ->execute([
                $companyId,
                $employeeId,
                $workDate,
                $clockIn,
                $clockOut,
                $status,
                $source,
                $note,
                $actorId,
            ]);
    }

    /**
     * Close out a register by marking every active person who has no record and
     * is not on approved leave. Existing marks and leave are left untouched.
     */
    public function markPresentForUnmarked(int $companyId, string $workDate, int $actorId): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO attendance_records
                (company_id, employee_id, work_date, clock_in, clock_out, status, source, note, recorded_by)
             SELECT e.company_id, e.id, ?, NULL, NULL, \'present\', \'manual\', NULL, ?
             FROM employees e
             WHERE e.company_id = ? AND e.status = \'active\'
               AND EXISTS (
                   SELECT 1 FROM employments em
                   WHERE em.company_id = e.company_id AND em.employee_id = e.id
                     AND em.start_date <= ? AND (em.end_date IS NULL OR em.end_date >= ?)
               )
               AND NOT EXISTS (
                   SELECT 1 FROM attendance_records a
                   WHERE a.company_id = e.company_id AND a.employee_id = e.id AND a.work_date = ?
               )
               AND NOT EXISTS (
                   SELECT 1 FROM leave_requests lr
                   WHERE lr.company_id = e.company_id AND lr.employee_id = e.id
                     AND lr.status = \'approved\' AND ? BETWEEN lr.start_date AND lr.end_date
               )',
        );
        $statement->execute([$workDate, $actorId, $companyId, $workDate, $workDate, $workDate, $workDate]);

        return $statement->rowCount();
    }

    /**
     * One person's rows between two dates, keyed by Y-m-d, for the timesheet.
     *
     * @return array<string,array<string,mixed>>
     */
    public function forEmployeeMonth(int $companyId, int $employeeId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT work_date, clock_in, clock_out, status, source, note
             FROM attendance_records
             WHERE company_id = ? AND employee_id = ? AND work_date BETWEEN ? AND ?
             ORDER BY work_date',
        );
        $statement->execute([$companyId, $employeeId, $from, $to]);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['work_date']] = $row;
        }

        return $rows;
    }

    /**
     * Active staff for a day, with their attendance row and whether they are on
     * approved leave. The leave flag is a subquery so one person cannot appear
     * twice when several leave requests overlap.
     *
     * @return list<array<string,mixed>>
     */
    public function day(int $companyId, string $workDate): array
    {
        $statement = $this->db->prepare(
            'SELECT e.id, e.employee_no, e.first_name, e.last_name, em.job_title,
                    d.name AS department_name,
                    a.status AS attendance_status, a.clock_in, a.clock_out, a.source,
                    EXISTS (
                        SELECT 1 FROM leave_requests lr
                        WHERE lr.company_id = e.company_id AND lr.employee_id = e.id
                          AND lr.status = \'approved\'
                          AND ? BETWEEN lr.start_date AND lr.end_date
                    ) AS on_leave
             FROM employees e
             LEFT JOIN employments em ON em.id = (
                 SELECT em2.id FROM employments em2
                 WHERE em2.employee_id = e.id AND em2.company_id = e.company_id
                 ORDER BY em2.start_date DESC, em2.id DESC LIMIT 1
             )
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN attendance_records a
                    ON a.employee_id = e.id AND a.company_id = e.company_id AND a.work_date = ?
             WHERE e.company_id = ? AND e.status = \'active\'
               AND EXISTS (
                   SELECT 1 FROM employments em2
                   WHERE em2.company_id = e.company_id AND em2.employee_id = e.id
                     AND em2.start_date <= ? AND (em2.end_date IS NULL OR em2.end_date >= ?)
               )
             ORDER BY e.last_name, e.first_name',
        );
        $statement->execute([$workDate, $workDate, $companyId, $workDate, $workDate]);

        return $statement->fetchAll();
    }
}
