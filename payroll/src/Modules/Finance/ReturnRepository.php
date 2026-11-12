<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;

/** Prepared statutory returns and the payroll lines they came from. */
final class ReturnRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, p.period AS run_period, p.label AS run_label,
                    u.first_name AS filed_first, u.last_name AS filed_last
             FROM statutory_returns r
             LEFT JOIN payroll_runs p ON p.id = r.run_id
             LEFT JOIN users u ON u.id = r.filed_by
             WHERE r.company_id = ?
             ORDER BY r.period DESC, r.type, r.id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function find(int $companyId, int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM statutory_returns WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$id, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function existing(int $companyId, string $type, string $period, int $runId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM statutory_returns
             WHERE company_id = ? AND type = ? AND period = ? AND run_id = ? LIMIT 1',
        );
        $statement->execute([$companyId, $type, $period, $runId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function create(int $companyId, array $data): int
    {
        $this->db->prepare(
            'INSERT INTO statutory_returns
                (company_id, type, period, run_id, amount_cents, due_date, payload, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $companyId,
            $data['type'],
            $data['period'],
            $data['run_id'],
            $data['amount_cents'],
            $data['due_date'],
            json_encode($data['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'prepared',
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function markFiled(int $companyId, int $id, string $reference, int $actorId): void
    {
        $this->db->prepare(
            'UPDATE statutory_returns
             SET status = "filed", reference_no = ?, filed_at = UTC_TIMESTAMP(), filed_by = ?
             WHERE id = ? AND company_id = ? AND status = "prepared"',
        )->execute([$reference === '' ? null : $reference, $actorId, $id, $companyId]);
    }

    /** @return list<array<string,mixed>> */
    public function linesForRun(int $runId): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, s.kra_pin, s.nssf_no, s.sha_no
             FROM payroll_lines l
             JOIN employees e ON e.id = l.employee_id
             LEFT JOIN employee_statutory s ON s.employee_id = e.id AND s.company_id = e.company_id
             WHERE l.run_id = ?
             ORDER BY l.employee_name, l.employee_no',
        );
        $statement->execute([$runId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function linesForYear(int $companyId, string $year): array
    {
        $statement = $this->db->prepare(
            'SELECT l.*, r.period, s.kra_pin, s.nssf_no, s.sha_no
             FROM payroll_lines l
             JOIN payroll_runs r ON r.id = l.run_id AND r.company_id = ?
             JOIN employees e ON e.id = l.employee_id
             LEFT JOIN employee_statutory s ON s.employee_id = e.id AND s.company_id = e.company_id
             WHERE r.status = "approved" AND r.period LIKE ?
             ORDER BY e.employee_no, r.period',
        );
        $statement->execute([$companyId, $year . '-%']);

        return $statement->fetchAll();
    }
}
