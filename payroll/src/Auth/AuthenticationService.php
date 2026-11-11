<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Auth;

use Mshahara\Payroll\Support\CsrfGuard;
use Mshahara\Payroll\Support\SessionManager;
use PDO;

/**
 * Session lifecycle.
 *
 * - The session id is regenerated on login (fixation).
 * - `session_epoch` is incremented on password change or "sign out
 *   everywhere"; a session whose epoch is stale is rejected on the next
 *   request, so revoking access does not depend on the client cooperating.
 * - Idle and absolute timeouts are enforced server-side.
 */
final class AuthenticationService
{
    private static ?string $dummyHash = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly SessionManager $session,
        private readonly CsrfGuard $csrf,
    ) {
    }

    public function attempt(string $email, string $password): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([strtolower(trim($email))]);
        $user = $statement->fetch();

        if ($user === false || !is_string($user['password_hash']) || $user['password_hash'] === '') {
            $this->wasteTime($password);

            return null;
        }

        if (!PasswordHasher::verify($password, $user['password_hash'])) {
            return null;
        }

        if ($user['status'] !== 'active') {
            return null;
        }

        if (PasswordHasher::needsRehash($user['password_hash'])) {
            $this->db
                ->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([PasswordHasher::hash($password), $user['id']]);
        }

        return $user;
    }

    /** @param array<string,mixed> $user */
    public function startSession(array $user): void
    {
        $this->session->regenerate();
        $this->session->replace([
            'user_id' => (int) $user['id'],
            'epoch' => (int) $user['session_epoch'],
            'created_at' => time(),
            'last_seen' => time(),
        ]);
        $this->session->identify((int) $user['id']);

        $this->csrf->rotate();

        $this->db
            ->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$user['id']]);
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        $userId = (int) $this->session->get('user_id', 0);

        if ($userId <= 0) {
            return null;
        }

        $now = time();

        if ($now - (int) $this->session->get('last_seen', 0) > (int) $this->config['session']['idle']) {
            return $this->expire();
        }

        if ($now - (int) $this->session->get('created_at', 0) > (int) $this->config['session']['absolute']) {
            return $this->expire();
        }

        $statement = $this->db->prepare('SELECT * FROM users WHERE id = ? AND status = ? LIMIT 1');
        $statement->execute([$userId, 'active']);
        $user = $statement->fetch();

        if ($user === false) {
            return $this->expire();
        }

        if ((int) $user['session_epoch'] !== (int) $this->session->get('epoch', -1)) {
            return $this->expire();
        }

        $this->session->put('last_seen', $now);

        return $user;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }

    /** Keep this device valid after a password change bumped the epoch. */
    public function refreshEpoch(int $epoch): void
    {
        $this->session->put('epoch', $epoch);
    }

    public function logoutEverywhere(int $userId): void
    {
        $this->db
            ->prepare('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?')
            ->execute([$userId]);

        $this->session->revokeUser($userId);

        if ((int) $this->session->get('user_id', 0) === $userId) {
            $this->session->destroy();
        }
    }

    private function expire(): ?array
    {
        $this->session->replace([]);
        $this->session->identify(null);
        $this->session->regenerate();

        return null;
    }

    private function wasteTime(string $password): void
    {
        self::$dummyHash ??= password_hash('not-a-real-password', PasswordHasher::algo());
        password_verify($password, self::$dummyHash);
    }
}
