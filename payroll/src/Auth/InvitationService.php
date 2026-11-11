<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

use InvalidArgumentException;
use Mshahara\Payroll\Authz\RoleRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Mailer;
use Mshahara\Payroll\Support\Token;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Invitations are the only way into the product.
 *
 * The company, role and email come from the stored row, never from the request,
 * so tampering with a hidden field cannot grant a different role or tenant.
 */
final class InvitationService
{
    public function __construct(
        private readonly PDO $db,
        private readonly InvitationRepository $invitationRepository,
        private readonly Mailer $mailer,
        private readonly AuditLogger $auditLogger,
        private readonly string $baseUrl,
        private readonly RoleRepository $roleRepository,
    ) {
    }

    /** @return array{invitation:array<string,mixed>,token:string} */
    public function create(int $companyId, string $email, string $roleKey, ?int $invitedBy = null): array
    {
        $email = strtolower(trim($email));
        $role = $this->roleRepository->findByKey($companyId, $roleKey);

        if ($role === null) {
            throw new InvalidArgumentException('That role does not exist for this company.');
        }

        $this->invitationRepository->revokePending($companyId, $email);

        $token = Token::generate();
        $this->invitationRepository->create($companyId, $email, (int) $role['id'], Token::hash($token), $invitedBy);

        $invite = $this->invitationRepository->findByTokenHash(Token::hash($token));
        if ($invite === null) {
            throw new RuntimeException('Invitation could not be read back.');
        }

        $company = (string) $invite['company_name'];
        $roleName = (string) $invite['role_name'];
        $url = rtrim($this->baseUrl, '/') . '/invite/' . $token;

        $this->mailer->send(
            $email,
            'You are invited to ' . $company,
            "Hello,\n\n{$company} has invited you to Mshahara Payroll as {$roleName}.\n\n"
            . "Open this link within 7 days to set your password:\n{$url}\n\n"
            . "If you were not expecting this, ignore the email.\n",
        );

        $this->auditLogger->record($companyId, $invitedBy, 'invite.created', 'invitation', (string) $invite['id'], [
            'email' => $email,
            'role' => $roleKey,
        ]);

        return ['invitation' => $invite, 'token' => $token];
    }

    /** @return array<string,mixed>|null */
    public function find(string $token): ?array
    {
        return $this->invitationRepository->findByTokenHash(Token::hash($token));
    }

    /** @return array<string,mixed>|null */
    public function userForEmail(string $email): ?array
    {
        return $this->invitationRepository->findUserByEmail($email);
    }

    /** @return list<array<string,mixed>> */
    public function pendingForCompany(int $companyId): array
    {
        return $this->invitationRepository->pendingForCompany($companyId);
    }

    public function revoke(int $companyId, int $invitationId, ?int $actorId = null): void
    {
        if (!$this->invitationRepository->revoke($companyId, $invitationId)) {
            throw new HttpException(404, 'That invitation is no longer pending.');
        }

        $this->auditLogger->record($companyId, $actorId, 'invite.revoked', 'invitation', (string) $invitationId);
    }

    /** @param array<string,mixed> $invite */
    public function acceptNew(array $invite, string $firstName, string $lastName, string $password): array
    {
        $email = strtolower((string) $invite['email']);
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($this->invitationRepository->findUserByEmail($email) !== null) {
            throw new HttpException(409, 'An account with that email already exists. Sign in to accept the invitation.');
        }

        $this->db->beginTransaction();

        try {
            $userId = $this->invitationRepository->createUser($firstName, $lastName, $email, PasswordHasher::hash($password));
            $this->attach($invite, $userId);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->auditLogger->record((int) $invite['company_id'], $userId, 'invite.accepted', 'user', (string) $userId, [
            'role' => $invite['role_key'],
        ]);

        $user = $this->invitationRepository->findUser($userId);

        if ($user === null) {
            throw new RuntimeException('Account could not be read after acceptance.');
        }

        return $user;
    }

    /** @param array<string,mixed> $invite @param array<string,mixed> $user */
    public function acceptExisting(array $invite, array $user): void
    {
        if (strtolower((string) $user['email']) !== strtolower((string) $invite['email'])) {
            throw new HttpException(403, 'This invitation is for a different email address.');
        }

        $this->db->beginTransaction();

        try {
            $this->attach($invite, (int) $user['id']);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->auditLogger->record((int) $invite['company_id'], (int) $user['id'], 'invite.accepted', 'user', (string) $user['id'], [
            'role' => $invite['role_key'],
        ]);
    }

    /** @param array<string,mixed> $invite */
    private function attach(array $invite, int $userId): void
    {
        $row = $this->invitationRepository->lockForAccept((int) $invite['id']);

        if ($row === null || $row['accepted_at'] !== null || $row['revoked_at'] !== null) {
            throw new HttpException(410, 'This invitation has expired or was already used.');
        }

        $companyId = (int) $row['company_id'];

        $this->invitationRepository->ensureMembership($companyId, $userId);
        $this->roleRepository->assign($userId, $companyId, (int) $row['role_id']);
        $this->invitationRepository->markAccepted((int) $invite['id']);
        $this->invitationRepository->activateUser($userId);
    }
}
