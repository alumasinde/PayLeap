<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use PDO;

/**
 * Read-only statutory table. Nothing legal is hard-coded in the leave service:
 * the seed values live in 0015 and a later rule is a new effective-dated row.
 */
final class StatutoryRuleRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(string $country = 'KE'): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM statutory_rules
             WHERE country_code = ? AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY category, code, effective_from DESC',
        );
        $statement->execute([$country]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function effective(string $code, string $asOf, string $country = 'KE'): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM statutory_rules
             WHERE country_code = ? AND code = ? AND effective_from <= ?
               AND (effective_to IS NULL OR effective_to >= ?)
             ORDER BY effective_from DESC LIMIT 1',
        );
        $statement->execute([$country, $code, $asOf, $asOf]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $code, string $asOf, float $fallback = 0.0, string $country = 'KE'): float
    {
        $rule = $this->effective($code, $asOf, $country);

        return $rule === null || $rule['value_decimal'] === null ? $fallback : (float) $rule['value_decimal'];
    }

    /** @return array<string,float> */
    public function values(string $asOf, string $country = 'KE'): array
    {
        $values = [];

        foreach ($this->all($country) as $rule) {
            if ($rule['value_decimal'] !== null && !isset($values[(string) $rule['code']])) {
                $values[(string) $rule['code']] = (float) $rule['value_decimal'];
            }
        }

        return $values;
    }
}
