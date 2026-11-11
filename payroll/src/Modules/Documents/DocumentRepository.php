<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Documents;

use PDO;

/**
 * Company reading material and per-employee progress. A document is complete
 * only when its read row reaches 100 percent.
 */
final class DocumentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId, bool $activeOnly = false): array
    {
        $sql = 'SELECT d.*,
                       (SELECT COUNT(*) FROM document_reads r
                        WHERE r.document_id = d.id AND r.company_id = d.company_id) AS readers,
                       (SELECT COUNT(*) FROM document_reads r
                        WHERE r.document_id = d.id AND r.company_id = d.company_id
                          AND r.status = \'completed\') AS completed
                FROM company_documents d
                WHERE d.company_id = ?';

        if ($activeOnly) {
            $sql .= ' AND d.active = 1';
        }

        $statement = $this->db->prepare($sql . ' ORDER BY d.published_at DESC, d.id DESC');
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $documentId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM company_documents WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$documentId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $data['company_id'] = $companyId;

        $columns = array_keys($data);
        $this->db
            ->prepare('INSERT INTO company_documents (' . implode(', ', $columns) . ')
                       VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    public function setActive(int $companyId, int $documentId, bool $active): void
    {
        $this->db
            ->prepare('UPDATE company_documents SET active = ? WHERE id = ? AND company_id = ?')
            ->execute([$active ? 1 : 0, $documentId, $companyId]);
    }

    public function delete(int $companyId, int $documentId): void
    {
        $this->db
            ->prepare('DELETE FROM company_documents WHERE id = ? AND company_id = ?')
            ->execute([$documentId, $companyId]);
    }

    /**
     * Active documents with this employee's progress.
     *
     * @return list<array<string,mixed>>
     */
    public function forEmployee(int $companyId, int $employeeId): array
    {
        $statement = $this->db->prepare(
            'SELECT d.*, r.progress_percent, r.seconds_spent, r.status AS read_status,
                    r.started_at, r.last_seen_at, r.completed_at
             FROM company_documents d
             LEFT JOIN document_reads r
               ON r.document_id = d.id AND r.company_id = d.company_id AND r.employee_id = ?
             WHERE d.company_id = ? AND d.active = 1
             ORDER BY d.published_at DESC, d.id DESC',
        );
        $statement->execute([$employeeId, $companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function read(int $companyId, int $documentId, int $employeeId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM document_reads
             WHERE company_id = ? AND document_id = ? AND employee_id = ?
             LIMIT 1',
        );
        $statement->execute([$companyId, $documentId, $employeeId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $state */
    public function saveRead(int $companyId, int $documentId, int $employeeId, array $state): void
    {
        $fields = [
            'company_id' => $companyId,
            'document_id' => $documentId,
            'employee_id' => $employeeId,
            'progress_percent' => $state['progress_percent'],
            'seconds_spent' => $state['seconds_spent'],
            'status' => $state['status'],
            'started_at' => $state['started_at'],
            'last_seen_at' => $state['last_seen_at'],
            'completed_at' => $state['completed_at'],
        ];

        $columns = array_keys($fields);
        $updates = implode(', ', array_map(
            static fn (string $column): string => $column . ' = VALUES(' . $column . ')',
            array_slice($columns, 3),
        ));

        $this->db
            ->prepare('INSERT INTO document_reads (' . implode(', ', $columns) . ')
                       VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')
                       ON DUPLICATE KEY UPDATE ' . $updates)
            ->execute(array_values($fields));
    }
}
