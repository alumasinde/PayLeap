<?php

declare(strict_types=1);

namespace Mshahara;

use Mshahara\Paye\Ruleset;

/**
 * The rulesets the app can price, keyed by effective date.
 *
 * Loaded from MySQL (the dynamic path) or, when the database is not
 * configured, from config/rates.php so the calculator still runs.
 */
final class RateSet
{
    /** @var list<array<string,mixed>> */
    private array $versions;

    /** @param list<array<string,mixed>> $versions */
    public function __construct(array $versions, private string $source)
    {
        usort(
            $versions,
            static fn (array $a, array $b): int => strcmp((string) $a['effective_from'], (string) $b['effective_from']),
        );

        if ($versions === []) {
            throw new \RuntimeException('No rate versions available');
        }

        foreach ($versions as $version) {
            Ruleset::assertValid($version);
        }

        $this->versions = $versions;
    }

    public static function fromDatabase(\PDO $db): self
    {
        $rows = $db->query(
            'SELECT id, label, effective_from, verified_on, payload FROM rate_versions ORDER BY effective_from'
        )->fetchAll();

        $versions = array_map(static function (array $row): array {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

            return [
                'id' => (string) $row['id'],
                'label' => (string) $row['label'],
                'effective_from' => (string) $row['effective_from'],
                'verified_on' => (string) $row['verified_on'],
            ] + $payload;
        }, $rows);

        return new self($versions, 'mysql');
    }

    public static function fromFile(string $path): self
    {
        $versions = require $path;
        if (!is_array($versions)) {
            throw new \RuntimeException('config/rates.php must return an array of rulesets');
        }

        return new self(array_values($versions), 'file');
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->versions;
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        return $this->versions[array_key_last($this->versions)];
    }

    /** @return array<string,mixed> */
    public function byId(string $id): array
    {
        foreach ($this->versions as $version) {
            if ($version['id'] === $id) {
                return $version;
            }
        }

        throw new \RuntimeException(sprintf('Unknown rate version "%s"', $id));
    }

    /** @return array<string,mixed> */
    public function forDate(string $isoDate): array
    {
        $stamp = strtotime($isoDate);
        if ($stamp === false) {
            throw new \InvalidArgumentException('forDate received an invalid date');
        }

        $applicable = array_values(array_filter(
            $this->versions,
            static fn (array $v): bool => strtotime((string) $v['effective_from']) <= $stamp,
        ));

        return $applicable === [] ? $this->versions[0] : $applicable[array_key_last($applicable)];
    }

    /** @return array<string,mixed> */
    public function forPeriod(int $year, int $month): array
    {
        return $this->forDate(sprintf('%04d-%02d-01', $year, $month));
    }

    /**
     * Every month the app can price, derived from the ruleset start dates: a
     * ruleset runs to the month before the next one starts, the latest to the
     * end of its year.
     *
     * @return list<array{year:int,months:list<int>}>
     */
    public function periods(): array
    {
        $byYear = [];

        foreach ($this->versions as $index => $version) {
            $start = self::monthOf((string) $version['effective_from']);
            $next = $this->versions[$index + 1] ?? null;

            if ($next !== null) {
                $nextStart = self::monthOf((string) $next['effective_from']);
                $end = $nextStart['month'] === 1
                    ? ['year' => $nextStart['year'] - 1, 'month' => 12]
                    : ['year' => $nextStart['year'], 'month' => $nextStart['month'] - 1];
            } else {
                $end = ['year' => $start['year'], 'month' => 12];
            }

            [$year, $month] = [$start['year'], $start['month']];
            $guard = 0;
            while (($year < $end['year'] || ($year === $end['year'] && $month <= $end['month'])) && $guard++ < 240) {
                $byYear[$year][$month] = true;

                $month++;
                if ($month > 12) {
                    $month = 1;
                    $year++;
                }
            }
        }

        ksort($byYear);
        $periods = [];
        foreach ($byYear as $year => $months) {
            $list = array_keys($months);
            sort($list);
            $periods[] = ['year' => (int) $year, 'months' => $list];
        }

        return $periods;
    }

    /** @return array{year:int,month:int} */
    public function defaultPeriod(): array
    {
        return self::monthOf((string) $this->current()['effective_from']);
    }

    /**
     * The period a fresh visit opens on: this month when it can be priced,
     * otherwise the latest period on record.
     *
     * @return array{year:int,month:int}
     */
    public function todayPeriod(): array
    {
        $year = (int) date('Y');
        $month = (int) date('n');

        if ($this->hasPeriod($year, $month)) {
            return ['year' => $year, 'month' => $month];
        }

        $periods = $this->periods();
        $last = $periods[array_key_last($periods)];

        return [
            'year' => (int) $last['year'],
            'month' => (int) $last['months'][array_key_last($last['months'])],
        ];
    }

    public function hasPeriod(int $year, int $month): bool
    {
        foreach ($this->periods() as $period) {
            if ($period['year'] === $year && in_array($month, $period['months'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{year:int,month:int} */
    private static function monthOf(string $isoDate): array
    {
        $parts = explode('-', $isoDate);

        return ['year' => (int) ($parts[0] ?? 0), 'month' => (int) ($parts[1] ?? 1)];
    }
}
