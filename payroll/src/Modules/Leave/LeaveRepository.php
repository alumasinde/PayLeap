<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;

/**
 * Leave types and requests. Every read carries company_id; a request can never
 * be opened or decided through another company.
 */
final class LeaveRepository
{
    public const DEFAULT_TYPES = [
        ['Annual leave', 21.00, 1, 0],
        ['Sick leave', 14.00, 1, 1],
        ['Maternity leave', 90.00, 1, 1],
        ['Paternity leave', 14.00, 1, 1],
        ['Compassionate leave', 5.00, 1, 0],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Safe to run more than once. */
    public function provisionDefaults(int $companyId): void
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO leave_types (company_id, name, days_per_year, paid, requires_document)
             VALUES (?, ?, ?, ?, ?)',
        );

        foreach (self::DEFAULT_TYPES as [$name, $days, $paid, $document]) {
            $statement->execute([$companyId, $name, $days, $paid, $document]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function types(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_types WHERE company_id = ? ORDER BY name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /**
     * Approved leave dates for one person, keyed by Y-m-d. Used by attendance
     * and the timesheet so an approved day is never treated as absent.
     *
     * @return array<string,true>
     */
    public function approvedDates(int $companyId, int $employeeId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT start_date, end_date
             FROM leave_requests
             WHERE company_id = ? AND employee_id = ? AND status = \'approved\'
               AND start_date <= ? AND end_date >= ?',
        );
        $statement->execute([$companyId, $employeeId, $to, $from]);

        $dates = [];

        foreach ($statement->fetchAll() as $request) {
            $cursor = new \DateTimeImmutable((string) $request['start_date']);
            $end = new \DateTimeImmutable((string) $request['end_date']);

            while ($cursor <= $end) {
                $date = $cursor->format('Y-m-d');

                if ($date >= $from && $date <= $to) {
                    $dates[$date] = true;
                }

                $cursor = $cursor->modify('+1 day');
            }
        }

        return $dates;
    }

    /**
     * Entitlement, approved days taken and pending days per leave type, for one
     * employee in a calendar year.
     *
     * @return list<array<string,mixed>>
     */
    public function balances(int $companyId, int $employeeId, int $year): array
    {
        $statement = $this->db->prepare(
            'SELECT lt.id, lt.name, lt.days_per_year,
                    COALESCE(SUM(CASE WHEN lr.status = \'approved\' THEN lr.days ELSE 0 END), 0) AS taken,
                    COALESCE(SUM(CASE WHEN lr.status = \'pending\' THEN lr.days ELSE 0 END), 0) AS pending
             FROM leave_types lt
             LEFT JOIN leave_requests lr
                    ON lr.leave_type_id = lt.id AND lr.company_id = lt.company_id
                   AND lr.employee_id = ? AND YEAR(lr.start_date) = ?
             WHERE lt.company_id = ?
             GROUP BY lt.id, lt.name, lt.days_per_year
             ORDER BY lt.name',
        );
        $statement->execute([$employeeId, $year, $companyId]);

        $rows = [];

        foreach ($statement->fetchAll() as $row) {
            $taken = (float) $row['taken'];
            $row['remaining'] = (float) $row['days_per_year'] - $taken;

            $rows[] = $row;
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $requestId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM leave_requests WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$requestId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listForCompany(int $companyId, string $status = 'all'): array
    {
        $where = ' WHERE lr.company_id = ?';
        $params = [$companyId];

        if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $where .= ' AND lr.status = ?';
            $params[] = $status;
        }

        $statement = $this->db->prepare(
            'SELECT lr.*, e.employee_no, e.first_name, e.last_name, e.job_title,
                    lt.name AS leave_type, d.name AS department_name
             FROM leave_requests lr
             JOIN employees e ON e.id = lr.employee_id AND e.company_id = lr.company_id
             JOIN leave_types lt ON lt.id = lr.leave_type_id AND lt.company_id = lr.company_id
             LEFT JOIN departments d ON d.id = e.department_id
             ' . $where . '
             ORDER BY (lr.status = \'pending\') DESC, lr.start_date DESC, lr.id DESC
             LIMIT 200',
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    public function pendingCount(int $companyId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM leave_requests WHERE company_id = ? AND status = ?',
        );
        $statement->execute([$companyId, 'pending']);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function pendingList(int $companyId, int $limit): array
    {
        $statement = $this->db->prepare(
            'SELECT lr.id, lr.start_date, lr.end_date, lr.days, lr.created_at,
                    e.first_name, e.last_name, e.employee_no,
                    lt.name AS leave_type
             FROM leave_requests lr
             JOIN employees e ON e.id = lr.employee_id AND e.company_id = lr.company_id
             JOIN leave_types lt ON lt.id = lr.leave_type_id AND lt.company_id = lr.company_id
             WHERE lr.company_id = ? AND lr.status = ?
             ORDER BY lr.start_date, lr.id
             LIMIT ' . max(1, min(50, $limit)),
        );
        $statement->execute([$companyId, 'pending']);

        return $statement->fetchAll();
    }

    /** Only a pending request can move. Returns false when it already moved. */
    public function decide(
        int $companyId,
        int $requestId,
        string $decision,
        int $actorId,
        ?string $note,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE leave_requests
             SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ?
             WHERE id = ? AND company_id = ? AND status = ?',
        );
        $statement->execute([$decision, $actorId, $note, $requestId, $companyId, 'pending']);

        return $statement->rowCount() === 1;
    }
}
