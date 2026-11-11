<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Companies;

use Mshahara\Payroll\Support\Session;
use PDO;

final class Companies
{
    public function __construct(
        private readonly PDO $db,
        private readonly Session $session,
    ) {
    }

    public function create(string $name, string $slug): int
    {
        $this->db
            ->prepare('INSERT INTO companies (name, slug) VALUES (?, ?)')
            ->execute([trim($name), strtolower(trim($slug))]);

        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function membershipsFor(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT m.company_id, m.role, m.status, c.name AS company_name, c.slug
             FROM memberships m
             JOIN companies c ON c.id = m.company_id
             WHERE m.user_id = ? AND m.status = ?
             ORDER BY m.created_at, c.name',
        );
        $statement->execute([$userId, 'active']);

        return $statement->fetchAll();
    }

    /**
     * Resolve the active company from the session membership list.
     *
     * @param list<array<string,mixed>> $memberships
     * @return array<string,mixed>|null
     */
    public function active(int $userId, array $memberships): ?array
    {
        $selected = (int) $this->session->get('active_company_id', 0);

        foreach ($memberships as $membership) {
            if ((int) $membership['company_id'] === $selected) {
                return $membership;
            }
        }

        if ($memberships === []) {
            $this->session->forget('active_company_id');

            return null;
        }

        $this->select((int) $memberships[0]['company_id']);

        return $memberships[0];
    }

    public function select(int $companyId): void
    {
        $this->session->put('active_company_id', $companyId);
    }

    /** @return array<string,mixed>|null */
    public function membership(int $userId, int $companyId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT m.company_id, m.role, m.status, c.name AS company_name, c.slug
             FROM memberships m
             JOIN companies c ON c.id = m.company_id
             WHERE m.user_id = ? AND m.company_id = ? AND m.status = ?
             LIMIT 1',
        );
        $statement->execute([$userId, $companyId, 'active']);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function members(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.name, u.email, m.role, m.status, m.created_at
             FROM memberships m
             JOIN users u ON u.id = m.user_id
             WHERE m.company_id = ?
             ORDER BY m.created_at',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
        $statement->execute([$companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
