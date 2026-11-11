<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Authz;

use PDO;

final class PermissionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<string,list<array<string,mixed>>> permissions keyed by module name
     */
    public function catalog(): array
    {
        $rows = $this->db
            ->query("SELECT p.`key`, p.name, p.description, COALESCE(m.name, 'Company') AS module_name
                     FROM permissions p
                     LEFT JOIN modules m ON m.id = p.module_id
                     ORDER BY (m.sort_order IS NULL), m.sort_order, p.name")
            ->fetchAll();

        $catalog = [];

        foreach ($rows as $row) {
            $catalog[(string) $row['module_name']][] = $row;
        }

        return $catalog;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->db->query('SELECT `key` FROM permissions')->fetchAll(PDO::FETCH_COLUMN);
    }
}
