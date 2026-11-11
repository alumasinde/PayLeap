<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

use PDO;

/**
 * Permission checks, resolved from the database.
 *
 * Code checks permissions, never role names. A company can change a role's
 * permissions at any time; the gate reads the current grants and caches them
 * for the rest of the request.
 */
final class PermissionGate
{
    /** @var array<string,array<string,true>> */
    private array $cache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function allows(int $userId, int $companyId, string $permission): bool
    {
        if ($userId <= 0 || $companyId <= 0 || $permission === '') {
            return false;
        }

        $cacheKey = $userId . ':' . $companyId;

        if (!isset($this->cache[$cacheKey])) {
            $statement = $this->db->prepare(
                'SELECT DISTINCT p.`key`
                 FROM user_roles ur
                 JOIN role_permissions rp ON rp.role_id = ur.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE ur.user_id = ? AND ur.company_id = ?',
            );
            $statement->execute([$userId, $companyId]);
            $this->cache[$cacheKey] = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
        }

        return isset($this->cache[$cacheKey][$permission]);
    }

    /** Drop the request cache after a role or assignment change. */
    public function forget(): void
    {
        $this->cache = [];
    }
}
