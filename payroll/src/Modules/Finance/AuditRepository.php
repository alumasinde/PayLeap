<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

use PDO;

/** Read-only access to the append-only audit trail. */
final class AuditRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{action?:string,entity?:string,user?:string,from?:string,to?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function search(int $companyId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($companyId, $filters);

        $statement = $this->db->prepare(
            'SELECT a.*, u.first_name, u.last_name, u.email
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ' . $where . '
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
        );
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /** @param array{action?:string,entity?:string,user?:string,from?:string,to?:string} $filters */
    public function count(int $companyId, array $filters): int
    {
        [$where, $params] = $this->where($companyId, $filters);

        $statement = $this->db->prepare('SELECT COUNT(*) FROM audit_log a ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{action?:string,entity?:string,user?:string,from?:string,to?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function where(int $companyId, array $filters): array
    {
        $where = ' WHERE a.company_id = ?';
        $params = [$companyId];

        if (($filters['action'] ?? '') !== '') {
            $where .= ' AND a.action LIKE ?';
            $params[] = '%' . $filters['action'] . '%';
        }

        if (($filters['entity'] ?? '') !== '') {
            $where .= ' AND a.entity = ?';
            $params[] = $filters['entity'];
        }

        if (($filters['user'] ?? '') !== '') {
            $where .= ' AND (u.email LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
            $like = '%' . $filters['user'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if (($filters['from'] ?? '') !== '') {
            $where .= ' AND a.created_at >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if (($filters['to'] ?? '') !== '') {
            $where .= ' AND a.created_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }

        return [$where, $params];
    }
}
