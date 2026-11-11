<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use Mshahara\Payroll\Support\Sessions\DatabaseHandler;
use PDO;
use RuntimeException;

/**
 * Native PHP session wrapper.
 *
 * Everything above this class talks to the session through these methods, so
 * controllers and services never touch $_SESSION directly and the storage can
 * be swapped (database sessions, encrypted cookies) without touching them.
 */
final class SessionManager
{
    /** @param array{name:string,idle:int,absolute:int,driver:string} $config */
    private function __construct(
        private readonly array $config,
        private readonly ?DatabaseHandler $handler = null,
    ) {
    }

    /** @param array{name:string,idle:int,absolute:int,driver:string} $config */
    public static function start(array $config, bool $secure, ?PDO $db = null): self
    {
        $handler = null;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string) $config['absolute']);

            if (($config['driver'] ?? 'file') === 'database') {
                if ($db === null) {
                    throw new RuntimeException('The database session driver needs a PDO connection.');
                }

                $handler = new DatabaseHandler($db);
                session_set_save_handler($handler, true);
            }

            session_name((string) $config['name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        return new self($config, $handler);
    }

    /** Associate the session row with an account, or null on sign-out. */
    public function identify(?int $userId): void
    {
        $this->handler?->identify($userId);
    }

    /** Delete every stored session for a user; returns rows removed. */
    public function revokeUser(int $userId): int
    {
        return $this->handler?->deleteForUser($userId) ?? 0;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /** @param array<string,mixed> $data */
    public function replace(array $data): void
    {
        $_SESSION = $data;
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->handler?->identify(null);
    }

    public function id(): string
    {
        return session_id() ?: '';
    }
}
