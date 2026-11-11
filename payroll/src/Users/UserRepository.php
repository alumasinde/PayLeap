<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Users;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function updateName(int $userId, string $firstName, string $lastName): void
    {
        $this->db
            ->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?')
            ->execute([$firstName, $lastName, $userId]);
    }

    public function passwordHash(int $userId): ?string
    {
        $statement = $this->db->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);
        $hash = $statement->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    /** Change the password, revoke every other session, and return the new epoch. */
    public function changePassword(int $userId, string $hash): int
    {
        $this->db
            ->prepare('UPDATE users SET password_hash = ?, session_epoch = session_epoch + 1, must_change_password = 0 WHERE id = ?')
            ->execute([$hash, $userId]);

        $statement = $this->db->prepare('SELECT session_epoch FROM users WHERE id = ?');
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }
}
