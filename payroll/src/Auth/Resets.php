<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

use Mshahara\Payroll\Support\Audit;
use Mshahara\Payroll\Support\Mailer;
use Mshahara\Payroll\Support\Token;
use PDO;

/**
 * Password resets. Tokens are single-use, hashed at rest, expire in an hour,
 * and completing a reset increments the user's session epoch so every other
 * session (and every other outstanding reset link) dies with it.
 */
final class Resets
{
    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly Audit $audit,
        private readonly string $baseUrl,
    ) {
    }

    /** Returns the token for local development and tests; null for unknown emails. */
    public function request(string $email, ?string $ip = null): ?string
    {
        $statement = $this->db->prepare("SELECT id, name, email FROM users WHERE email = ? AND status <> 'suspended' LIMIT 1");
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();

        if ($user === false) {
            return null;
        }

        $this->db
            ->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')
            ->execute([$user['id']]);

        $token = Token::generate();

        $this->db
            ->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, ip)
                       VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 MINUTE), ?)')
            ->execute([$user['id'], Token::hash($token), $ip]);

        $url = rtrim($this->baseUrl, '/') . '/reset/' . $token;

        $this->mailer->send(
            (string) $user['email'],
            'Reset your Mshahara password',
            "Hello,\n\nUse this link within an hour to choose a new password:\n{$url}\n\n"
            . "If you did not ask for this, ignore the email. Nothing has changed.\n",
        );

        $this->audit->record(null, (int) $user['id'], 'password.reset.requested', 'user', (string) $user['id']);

        return $token;
    }

    /** @return array<string,mixed>|null */
    public function find(string $token): ?array
    {
        $statement = $this->db->prepare(
            'SELECT r.*, u.name, u.email
             FROM password_resets r
             JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > UTC_TIMESTAMP()
             LIMIT 1',
        );
        $statement->execute([Token::hash($token)]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $reset */
    public function complete(array $reset, string $password): int
    {
        $userId = (int) $reset['user_id'];

        $this->db->beginTransaction();

        try {
            $this->db
                ->prepare('UPDATE users
                           SET password_hash = ?, session_epoch = session_epoch + 1, status = ?,
                               email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
                           WHERE id = ?')
                ->execute([Passwords::hash($password), 'active', $userId]);

            $this->db
                ->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND used_at IS NULL')
                ->execute([$userId]);

            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->audit->record(null, $userId, 'password.reset.completed', 'user', (string) $userId);

        return $userId;
    }
}
