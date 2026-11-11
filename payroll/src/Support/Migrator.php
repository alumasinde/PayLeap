<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use PDO;
use RuntimeException;

/**
 * Paired SQL migrations.
 *
 * Every migration is two files: NNNN_name.up.sql and NNNN_name.down.sql. The
 * migrations table stores the base name (for example 0001_kernel), so up and
 * down always agree on which step they belong to. Files run in filename order
 * and roll back in reverse.
 */
final class Migrator
{
    private const UP = '.up.sql';
    private const DOWN = '.down.sql';

    public function __construct(
        private readonly PDO $db,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> ids applied by this call */
    public function run(): array
    {
        $this->ensureTable();

        $up = $this->files(self::UP);
        $applied = $this->applied();
        $unknown = array_values(array_diff($applied, array_keys($up)));

        if ($unknown !== []) {
            throw new RuntimeException(
                'This database was migrated by files that no longer exist (' . implode(', ', $unknown) . '). '
                . 'Drop and recreate the database, then run migrate again.',
            );
        }

        $ran = [];

        foreach ($up as $id => $file) {
            if (in_array($id, $applied, true)) {
                continue;
            }

            $this->execute($file);
            $this->db->prepare('INSERT INTO migrations (id) VALUES (?)')->execute([$id]);
            $ran[] = $id;
        }

        return $ran;
    }

    /**
     * Roll back the most recent migrations, newest first.
     *
     * @return list<string> ids rolled back by this call
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureTable();

        $steps = max(1, $steps);
        $down = $this->files(self::DOWN);
        $applied = $this->applied();
        rsort($applied, SORT_STRING);

        $ran = [];

        foreach ($applied as $id) {
            if (count($ran) >= $steps) {
                break;
            }

            if (!isset($down[$id])) {
                throw new RuntimeException("Migration {$id} has no down file, so it cannot be rolled back.");
            }

            $this->execute($down[$id]);
            $this->db->prepare('DELETE FROM migrations WHERE id = ?')->execute([$id]);
            $ran[] = $id;
        }

        return $ran;
    }

    /** @return list<string> */
    public function reset(): array
    {
        return $this->rollback(PHP_INT_MAX);
    }

    /** @return array{applied:list<string>,pending:list<string>} */
    public function status(): array
    {
        $this->ensureTable();

        $applied = $this->applied();
        $pending = array_values(array_diff(array_keys($this->files(self::UP)), $applied));

        return ['applied' => $applied, 'pending' => $pending];
    }

    public static function createDatabase(PDO $server, string $name): void
    {
        $safe = str_replace('`', '', $name);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    public static function dropDatabase(PDO $server, string $name): void
    {
        $safe = str_replace('`', '', $name);
        $server->exec("DROP DATABASE IF EXISTS `{$safe}`");
    }

    private function ensureTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4');
    }

    /** @return list<string> */
    private function applied(): array
    {
        return $this->db->query('SELECT id FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /** @return array<string,string> id => path */
    private function files(string $suffix): array
    {
        $files = glob($this->directory . '/*' . $suffix) ?: [];
        sort($files, SORT_STRING);

        $mapped = [];

        foreach ($files as $file) {
            $name = basename($file);
            $mapped[substr($name, 0, -strlen($suffix))] = $file;
        }

        return $mapped;
    }

    private function execute(string $file): void
    {
        $sql = (string) file_get_contents($file);

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);

            if ($statement === '') {
                continue;
            }

            try {
                $this->db->exec($statement);
            } catch (\PDOException $error) {
                throw new RuntimeException(
                    'Migration ' . basename($file) . ' failed: ' . $error->getMessage()
                    . "\n" . $statement,
                    0,
                    $error,
                );
            }
        }
    }
}
