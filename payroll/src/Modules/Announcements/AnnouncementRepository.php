<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Announcements;

use PDO;

/** SQL for company-wide HR announcements. Every read is scoped by company. */
final class AnnouncementRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM company_announcements
             WHERE company_id = ?
             ORDER BY published_at DESC, id DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function latest(int $companyId, int $limit = 2): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->db->prepare(
            'SELECT * FROM company_announcements
             WHERE company_id = ?
             ORDER BY published_at DESC, id DESC
             LIMIT ' . $limit,
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $announcementId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM company_announcements WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$announcementId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $data['company_id'] = $companyId;
        $columns = array_keys($data);

        $this->db
            ->prepare('INSERT INTO company_announcements (' . implode(', ', $columns) . ')
                       VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
            ->execute(array_values($data));

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $companyId, int $announcementId): void
    {
        $this->db
            ->prepare('DELETE FROM company_announcements WHERE id = ? AND company_id = ?')
            ->execute([$announcementId, $companyId]);
    }
}