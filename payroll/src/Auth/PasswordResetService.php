<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\Mailer;
use PDO;

/**
 * Forgotten passwords. A six-character temporary password is emailed, stored
 * hashed, and flagged so the account must choose a new password before it can
 * use the workspace. Replacing the hash also revokes every other session.
 */
final class PasswordResetService
{
    private const TEMP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const TEMP_LENGTH = 6;

    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** Returns the temporary password for local development and tests; null for unknown emails. */
    public function request(string $email): ?string
    {
        $statement = $this->db->prepare("SELECT id, email FROM users WHERE email = ? AND status <> 'suspended' LIMIT 1");
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();

        if ($user === false) {
            return null;
        }

        $temporary = $this->temporaryPassword();

        $this->db
            ->prepare('UPDATE users
                       SET password_hash = ?, must_change_password = 1,
                           session_epoch = session_epoch + 1, status = ?,
                           email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
                       WHERE id = ?')
            ->execute([PasswordHasher::hash($temporary), 'active', $user['id']]);

        $this->mailer->send(
            (string) $user['email'],
            'Your temporary Mshahara password',
            "Hello,\n\nUse this temporary password to sign in:\n\n  {$temporary}\n\n"
            . 'You will be asked to choose a new password (at least ' . PasswordHasher::MIN_LENGTH . " characters) after signing in.\n\n"
            . "If you did not ask for this, contact your administrator.\n",
        );

        $this->auditLogger->record(null, (int) $user['id'], 'password.temp.issued', 'user', (string) $user['id']);

        return $temporary;
    }

    private function temporaryPassword(): string
    {
        $alphabet = self::TEMP_ALPHABET;
        $last = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < self::TEMP_LENGTH; $i++) {
            $password .= $alphabet[random_int(0, $last)];
        }

        return $password;
    }
}
