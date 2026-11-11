<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

use PDO;

/**
 * SQL for invitations and the membership they create.
 * Mail, tokens and transactions stay in InvitationService.
 */
final class InvitationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function revokePending(int $companyId, string $email): void
    {
        $this->db
            ->prepare('UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
                       WHERE company_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL')
            ->execute([$companyId, $email]);
    }

    public function create(int $companyId, string $email, int $roleId, string $tokenHash, ?int $invitedBy): void
    {
        $this->db
            ->prepare('INSERT INTO invitations (company_id, email, role_id, token_hash, invited_by, expires_at)
                       VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))')
            ->execute([$companyId, $email, $roleId, $tokenHash, $invitedBy]);
    }

    /** @return array<string,mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->db->prepare(
            'SELECT i.*, c.name AS company_name, c.slug AS company_slug,
                    r.`key` AS role_key, r.name AS role_name
             FROM invitations i
             JOIN companies c ON c.id = i.company_id
             JOIN roles r ON r.id = i.role_id
             WHERE i.token_hash = ?
               AND i.accepted_at IS NULL
               AND i.revoked_at IS NULL
               AND i.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findUserByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([strtolower($email)]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function createUser(string $firstName, string $lastName, string $email, string $passwordHash): int
    {
        $this->db
            ->prepare('INSERT INTO users (first_name, last_name, email, password_hash, email_verified_at, status)
                       VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)')
            ->execute([$firstName, $lastName, strtolower($email), $passwordHash, 'active']);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function lockForAccept(int $inviteId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT company_id, role_id, accepted_at, revoked_at
             FROM invitations
             WHERE id = ? AND expires_at > UTC_TIMESTAMP()
             FOR UPDATE',
        );
        $statement->execute([$inviteId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function ensureMembership(int $companyId, int $userId): void
    {
        $this->db
            ->prepare('INSERT INTO memberships (company_id, user_id, status) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE status = VALUES(status)')
            ->execute([$companyId, $userId, 'active']);
    }

    /** @return list<array<string,mixed>> */
    public function pendingForCompany(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT i.id, i.email, i.expires_at, r.name AS role_name
             FROM invitations i
             JOIN roles r ON r.id = i.role_id
             WHERE i.company_id = ?
               AND i.accepted_at IS NULL
               AND i.revoked_at IS NULL
               AND i.expires_at > UTC_TIMESTAMP()
             ORDER BY i.created_at DESC',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    public function revoke(int $companyId, int $invitationId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
             WHERE id = ? AND company_id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
        );
        $statement->execute([$invitationId, $companyId]);

        return $statement->rowCount() > 0;
    }

    public function markAccepted(int $inviteId): void
    {
        $this->db
            ->prepare('UPDATE invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$inviteId]);
    }

    public function activateUser(int $userId): void
    {
        $this->db
            ->prepare('UPDATE users SET status = ?, email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')
            ->execute(['active', $userId]);
    }

    /** @return array<string,mixed>|null */
    public function findUser(int $userId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
