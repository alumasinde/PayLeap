<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly PDO $db,
        private readonly string $directory,
    ) {
    }

    /** @return list<string> */
    public function run(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id VARCHAR(120) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4');

        $applied = $this->db->query('SELECT id FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $ran = [];

        foreach (glob($this->directory . '/*.sql') ?: [] as $file) {
            $id = basename($file);
            if (in_array($id, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);

            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement === '') {
                    continue;
                }

                $this->db->exec($statement);
            }

            $this->db->prepare('INSERT INTO migrations (id) VALUES (?)')->execute([$id]);
            $ran[] = $id;
        }

        return $ran;
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
}
