<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Payroll;

use PDO;
use RuntimeException;

/**
 * Statutory rate versions, stored as JSON payloads in MySQL.
 *
 * A run asks for the version effective in its month, so a new gazetted rate is
 * a new row and an old run keeps the version it used.
 */
final class RateVersionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** The version in force on a date, or null before the first one. */
    public function forDate(string $isoDate): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, label, effective_from, verified_on, payload
             FROM rate_versions
             WHERE effective_from <= ?
             ORDER BY effective_from DESC, id DESC
             LIMIT 1',
        );
        $statement->execute([$isoDate]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->db
            ->query('SELECT id, label, effective_from, verified_on, payload
                     FROM rate_versions
                     ORDER BY effective_from, id')
            ->fetchAll();

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, label, effective_from, verified_on, payload
             FROM rate_versions
             WHERE id = ?
             LIMIT 1',
        );
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Flatten a row into the shape Ruleset and Calculator expect.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $payload = json_decode((string) $row['payload'], true);

        if (!is_array($payload)) {
            throw new RuntimeException('Rate version ' . $row['id'] . ' has an unreadable payload.');
        }

        return [
            'id' => (string) $row['id'],
            'label' => (string) $row['label'],
            'effective_from' => (string) $row['effective_from'],
            'verified_on' => (string) $row['verified_on'],
        ] + $payload;
    }
}
