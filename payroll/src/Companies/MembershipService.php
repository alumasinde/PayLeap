<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Companies;

use Mshahara\Payroll\Authz\RoleRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use PDO;
use Throwable;

/**
 * Changing who is in a company and what they may do.
 *
 * Two guards: nobody changes their own role, and the last owner cannot be
 * demoted or removed.
 */
final class MembershipService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CompanyRepository $companyRepository,
        private readonly RoleRepository $roleRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function changeRole(int $companyId, int $userId, int $roleId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw new HttpException(403, 'You cannot change your own role.');
        }

        if ($this->companyRepository->membership($userId, $companyId) === null) {
            throw new HttpException(404, 'That person is not in this company.');
        }

        $role = $this->roleRepository->find($roleId);

        if ($role === null || (int) $role['company_id'] !== $companyId) {
            throw new HttpException(404, 'That role does not exist for this company.');
        }

        $current = $this->roleRepository->rolesForUser($userId, $companyId);

        if ($this->isLastOwner($companyId, $current) && $role['key'] !== 'owner') {
            throw new HttpException(409, 'The last owner cannot be demoted.');
        }

        $this->db->beginTransaction();

        try {
            $this->roleRepository->clearForUser($userId, $companyId);
            $this->roleRepository->assign($userId, $companyId, $roleId);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->auditLogger->record($companyId, $actorId, 'member.role_changed', 'user', (string) $userId, [
            'from' => array_column($current, 'key'),
            'to' => [$role['key']],
        ]);
    }

    public function remove(int $companyId, int $userId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw new HttpException(403, 'You cannot remove yourself.');
        }

        if ($this->companyRepository->membership($userId, $companyId) === null) {
            throw new HttpException(404, 'That person is not in this company.');
        }

        $current = $this->roleRepository->rolesForUser($userId, $companyId);

        if ($this->isLastOwner($companyId, $current)) {
            throw new HttpException(409, 'The last owner cannot be removed.');
        }

        $this->db->beginTransaction();

        try {
            $this->companyRepository->removeMembership($companyId, $userId);
            $this->roleRepository->clearForUser($userId, $companyId);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->auditLogger->record($companyId, $actorId, 'member.removed', 'user', (string) $userId, [
            'roles' => array_column($current, 'key'),
        ]);
    }

    /** @param list<array<string,mixed>> $roles */
    private function isLastOwner(int $companyId, array $roles): bool
    {
        if (!in_array('owner', array_column($roles, 'key'), true)) {
            return false;
        }

        return $this->roleRepository->holderCount($companyId, 'owner') <= 1;
    }
}
