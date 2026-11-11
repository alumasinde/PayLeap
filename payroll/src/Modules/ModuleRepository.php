<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules;

use PDO;

final class ModuleRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,array<string,mixed>> keyed by module key */
    public function all(): array
    {
        $rows = $this->db
            ->query('SELECT `key`, name, description AS note, status,
                            permission_key AS permission
                     FROM modules
                     ORDER BY sort_order, name')
            ->fetchAll();

        $modules = [];

        foreach ($rows as $row) {
            $modules[(string) $row['key']] = $row;
        }

        return $modules;
    }
}
