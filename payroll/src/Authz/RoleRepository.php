<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

use PDO;

/**
 * Roles, their permissions, and who holds them.
 *
 * Every company owns its role rows. The system templates (company_id NULL) are
 * copied into a company when it is created; from then on that company can edit
 * its copies freely.
 */
final class RoleRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByKey(int $companyId, string $key): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM roles WHERE company_id = ? AND `key` = ? LIMIT 1',
        );
        $statement->execute([$companyId, $key]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $roleId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM roles WHERE id = ? LIMIT 1');
        $statement->execute([$roleId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function forCompany(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count,
                    (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS member_count
             FROM roles r
             WHERE r.company_id = ?
             ORDER BY r.is_system DESC, r.name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** Copy the system templates into a company. Safe to run twice. */
    public function provision(int $companyId): void
    {
        $this->db
            ->prepare('INSERT IGNORE INTO roles (company_id, `key`, name, is_system)
                       SELECT ?, sr.`key`, sr.name, 0
                       FROM roles sr
                       WHERE sr.company_id IS NULL')
            ->execute([$companyId]);

        $this->db
            ->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id)
                       SELECT cr.id, rp.permission_id
                       FROM roles cr
                       JOIN roles sr ON sr.company_id IS NULL AND sr.`key` = cr.`key`
                       JOIN role_permissions rp ON rp.role_id = sr.id
                       WHERE cr.company_id = ?')
            ->execute([$companyId]);
    }

    /** @return list<string> */
    public function permissionsFor(int $roleId): array
    {
        $statement = $this->db->prepare(
            'SELECT p.`key`
             FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.`key`',
        );
        $statement->execute([$roleId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function grant(int $roleId, string $permission): void
    {
        $this->db
            ->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id)
                       SELECT ?, id FROM permissions WHERE `key` = ?')
            ->execute([$roleId, $permission]);
    }

    public function deny(int $roleId, string $permission): void
    {
        $this->db
            ->prepare('DELETE rp FROM role_permissions rp
                       JOIN permissions p ON p.id = rp.permission_id
                       WHERE rp.role_id = ? AND p.`key` = ?')
            ->execute([$roleId, $permission]);
    }

    /** @param list<string> $permissionKeys */
    public function setPermissions(int $roleId, array $permissionKeys): void
    {
        $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);

        $insert = $this->db->prepare(
            'INSERT INTO role_permissions (role_id, permission_id)
             SELECT ?, id FROM permissions WHERE `key` = ?',
        );

        foreach ($permissionKeys as $key) {
            $insert->execute([$roleId, $key]);
        }
    }

    public function rolesWithPermissionCount(int $companyId, string $permissionKey): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(DISTINCT r.id)
             FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.company_id = ? AND p.`key` = ?',
        );
        $statement->execute([$companyId, $permissionKey]);

        return (int) $statement->fetchColumn();
    }

    public function assign(int $userId, int $companyId, int $roleId): void
    {
        $this->db
            ->prepare('INSERT IGNORE INTO user_roles (user_id, company_id, role_id) VALUES (?, ?, ?)')
            ->execute([$userId, $companyId, $roleId]);
    }

    public function unassign(int $userId, int $companyId, int $roleId): void
    {
        $this->db
            ->prepare('DELETE FROM user_roles WHERE user_id = ? AND company_id = ? AND role_id = ?')
            ->execute([$userId, $companyId, $roleId]);
    }

    /** @return list<array<string,mixed>> */
    public function rolesForUser(int $userId, int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.id, r.`key`, r.name
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND ur.company_id = ?
             ORDER BY r.name',
        );
        $statement->execute([$userId, $companyId]);

        return $statement->fetchAll();
    }

    public function clearForUser(int $userId, int $companyId): void
    {
        $this->db
            ->prepare('DELETE FROM user_roles WHERE user_id = ? AND company_id = ?')
            ->execute([$userId, $companyId]);
    }

    /** @return array<int,list<int>> role ids keyed by user id */
    public function roleIdsByUser(int $companyId): array
    {
        $statement = $this->db->prepare('SELECT user_id, role_id FROM user_roles WHERE company_id = ?');
        $statement->execute([$companyId]);

        $assigned = [];

        foreach ($statement->fetchAll() as $row) {
            $assigned[(int) $row['user_id']][] = (int) $row['role_id'];
        }

        return $assigned;
    }

    public function holderCount(int $companyId, string $roleKey): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(DISTINCT ur.user_id)
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.company_id = ? AND r.company_id = ? AND r.`key` = ?',
        );
        $statement->execute([$companyId, $companyId, $roleKey]);

        return (int) $statement->fetchColumn();
    }
}
