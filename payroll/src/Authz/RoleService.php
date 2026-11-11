<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use PDO;
use Throwable;

final class RoleService
{
    public function __construct(
        private readonly PDO $db,
        private readonly RoleRepository $roleRepository,
        private readonly PermissionRepository $permissionRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param list<string> $permissions */
    public function update(int $companyId, int $roleId, string $name, array $permissions, int $actorId): void
    {
        $role = $this->roleRepository->find($roleId);

        if ($role === null || (int) $role['company_id'] !== $companyId) {
            throw new HttpException(404, 'That role does not exist for this company.');
        }

        if ($role['key'] === 'owner') {
            throw new HttpException(403, 'The owner role is fixed.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new HttpException(422, 'Give the role a name of 1 to 80 characters.');
        }

        $permissions = array_values(array_unique(array_filter($permissions, 'is_string')));
        $unknown = array_diff($permissions, $this->permissionRepository->keys());

        if ($unknown !== []) {
            throw new HttpException(422, 'That permission does not exist.');
        }

        $current = $this->roleRepository->permissionsFor($roleId);
        $removingManage = in_array('roles.manage', $current, true) && !in_array('roles.manage', $permissions, true);

        if ($removingManage && $this->roleRepository->rolesWithPermissionCount($companyId, 'roles.manage') <= 1) {
            throw new HttpException(409, 'At least one role must keep the roles.manage permission.');
        }

        $this->db->beginTransaction();

        try {
            $this->db->prepare('UPDATE roles SET name = ? WHERE id = ?')->execute([$name, $roleId]);
            $this->roleRepository->setPermissions($roleId, $permissions);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->auditLogger->record($companyId, $actorId, 'role.updated', 'role', (string) $roleId, [
            'name' => $name,
            'from' => $current,
            'to' => $permissions,
        ]);
    }
}
