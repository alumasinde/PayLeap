<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support\Sessions;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * MySQL-backed PHP session handler.
 *
 * The last writer wins: requests sharing a session are not serialised by an
 * advisory lock. That is safe here because authentication state (user, epoch,
 * membership) is re-read from the database on every request; the worst case is
 * a lost flash message from two simultaneous requests, not a stale login.
 */
final class DatabaseHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?int $userId = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function identify(?int $userId): void
    {
        $this->userId = $userId === null || $userId <= 0 ? null : $userId;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $statement = $this->db->prepare('SELECT data, user_id FROM sessions WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();

        if ($row === false) {
            $this->userId = null;

            return '';
        }

        $this->userId = $row['user_id'] === null ? null : (int) $row['user_id'];

        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $this->db
            ->prepare('INSERT INTO sessions (id, user_id, data, last_activity) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), data = VALUES(data), last_activity = VALUES(last_activity)')
            ->execute([$id, $this->userId, $data, time()]);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->db->prepare('DELETE FROM sessions WHERE last_activity < ?');
        $statement->execute([time() - $max_lifetime]);

        return $statement->rowCount();
    }

    public function deleteForUser(int $userId): int
    {
        $statement = $this->db->prepare('DELETE FROM sessions WHERE user_id = ?');
        $statement->execute([$userId]);

        return $statement->rowCount();
    }

    public function validateId(string $id): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM sessions WHERE id = ? LIMIT 1');
        $statement->execute([$id]);

        return $statement->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->db
            ->prepare('UPDATE sessions SET last_activity = ? WHERE id = ?')
            ->execute([time(), $id]);

        return true;
    }
}
