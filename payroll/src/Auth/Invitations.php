<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

use Mshahara\Payroll\Support\Audit;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Mailer;
use Mshahara\Payroll\Support\Token;
use PDO;

/**
 * Invitations are the only way into the product.
 *
 * The company, role and email come from the stored row, never from the request,
 * so tampering with a hidden field cannot grant a different role or tenant.
 */
final class Invitations
{
    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly Audit $audit,
        private readonly string $baseUrl,
    ) {
    }

    /** @return array{invitation:array<string,mixed>,token:string} */
    public function create(int $companyId, string $email, string $role, ?int $invitedBy = null): array
    {
        $email = strtolower(trim($email));

        $this->db
            ->prepare('UPDATE invitations SET revoked_at = UTC_TIMESTAMP()
                       WHERE company_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL')
            ->execute([$companyId, $email]);

        $token = Token::generate();

        $this->db
            ->prepare('INSERT INTO invitations (company_id, email, role, token_hash, invited_by, expires_at)
                       VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY))')
            ->execute([$companyId, $email, $role, Token::hash($token), $invitedBy]);

        $invite = $this->find($token);
        if ($invite === null) {
            throw new \RuntimeException('Invitation could not be read back.');
        }

        $company = (string) $invite['company_name'];
        $url = rtrim($this->baseUrl, '/') . '/invite/' . $token;

        $this->mailer->send(
            $email,
            'You are invited to ' . $company,
            "Hello,\n\n{$company} has invited you to Mshahara Payroll as {$role}.\n\n"
            . "Open this link within 7 days to set your password:\n{$url}\n\n"
            . "If you were not expecting this, ignore the email.\n",
        );

        $this->audit->record($companyId, $invitedBy, 'invite.created', 'invitation', (string) $invite['id'], [
            'email' => $email,
            'role' => $role,
        ]);

        return ['invitation' => $invite, 'token' => $token];
    }

    /** @return array<string,mixed>|null */
    public function find(string $token): ?array
    {
        $statement = $this->db->prepare(
            'SELECT i.*, c.name AS company_name, c.slug AS company_slug
             FROM invitations i
             JOIN companies c ON c.id = i.company_id
             WHERE i.token_hash = ?
               AND i.accepted_at IS NULL
               AND i.revoked_at IS NULL
               AND i.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
        );
        $statement->execute([Token::hash($token)]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $invite */
    public function acceptNew(array $invite, string $name, string $password): array
    {
        $email = strtolower((string) $invite['email']);
        $name = trim($name);

        $exists = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);

        if ($exists->fetch() !== false) {
            throw new HttpException(409, 'An account with that email already exists. Sign in to accept the invitation.');
        }

        $this->db->beginTransaction();

        try {
            $this->db
                ->prepare('INSERT INTO users (name, email, password_hash, email_verified_at, status)
                           VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)')
                ->execute([$name, $email, Passwords::hash($password), 'active']);

            $userId = (int) $this->db->lastInsertId();

            $this->attach($invite, $userId);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->audit->record((int) $invite['company_id'], $userId, 'invite.accepted', 'user', (string) $userId, [
            'role' => $invite['role'],
        ]);

        $statement = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $user = $statement->fetch();

        if ($user === false) {
            throw new \RuntimeException('Account could not be read after acceptance.');
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
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->audit->record((int) $invite['company_id'], (int) $user['id'], 'invite.accepted', 'user', (string) $user['id'], [
            'role' => $invite['role'],
        ]);
    }

    /** @param array<string,mixed> $invite */
    private function attach(array $invite, int $userId): void
    {
        $lock = $this->db->prepare(
            'SELECT company_id, role, accepted_at, revoked_at
             FROM invitations
             WHERE id = ? AND expires_at > UTC_TIMESTAMP()
             FOR UPDATE',
        );
        $lock->execute([(int) $invite['id']]);
        $row = $lock->fetch();

        if ($row === false || $row['accepted_at'] !== null || $row['revoked_at'] !== null) {
            throw new HttpException(410, 'This invitation has expired or was already used.');
        }

        $this->db
            ->prepare('INSERT INTO memberships (company_id, user_id, role, status)
                       VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE role = VALUES(role), status = VALUES(status)')
            ->execute([(int) $row['company_id'], $userId, (string) $row['role'], 'active']);

        $this->db
            ->prepare('UPDATE invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([(int) $invite['id']]);

        $this->db
            ->prepare('UPDATE users SET status = ?, email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()) WHERE id = ?')
            ->execute(['active', $userId]);
    }
}
