<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use PDO;

/**
 * Public holidays by company. Fixed statutory days are provisioned; a company
 * adds movable feasts and its own days. The timesheet reads these dates, so a
 * holiday never counts as lost time and work on it is reported separately.
 */
final class HolidayRepository
{
    private const FIXED = [
        ['01-01', "New Year's Day"],
        ['05-01', 'Labour Day'],
        ['06-01', 'Madaraka Day'],
        ['10-20', 'Mashujaa Day'],
        ['12-12', 'Jamhuri Day'],
        ['12-25', 'Christmas Day'],
        ['12-26', 'Boxing Day'],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Fixed statutory days for this year and next. Safe to run more than once. */
    public function provisionDefaults(int $companyId): void
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO public_holidays (company_id, holiday_date, name)
             VALUES (?, ?, ?)',
        );
        $year = (int) date('Y');

        foreach ([$year, $year + 1] as $target) {
            foreach (self::FIXED as [$suffix, $name]) {
                $statement->execute([$companyId, $target . '-' . $suffix, $name]);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function forYear(int $companyId, int $year): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM public_holidays
             WHERE company_id = ? AND YEAR(holiday_date) = ?
             ORDER BY holiday_date',
        );
        $statement->execute([$companyId, $year]);

        return $statement->fetchAll();
    }

    /**
     * Dates between two days, keyed by Y-m-d.
     *
     * @return array<string,string>
     */
    public function between(int $companyId, string $from, string $to): array
    {
        $statement = $this->db->prepare(
            'SELECT holiday_date, name FROM public_holidays
             WHERE company_id = ? AND holiday_date BETWEEN ? AND ?
             ORDER BY holiday_date',
        );
        $statement->execute([$companyId, $from, $to]);

        $dates = [];

        foreach ($statement->fetchAll() as $row) {
            $dates[(string) $row['holiday_date']] = (string) $row['name'];
        }

        return $dates;
    }

    /** @return array<string,mixed>|null */
    public function onDate(int $companyId, string $date): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM public_holidays WHERE company_id = ? AND holiday_date = ? LIMIT 1',
        );
        $statement->execute([$companyId, $date]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $holidayId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM public_holidays WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$holidayId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function dateExists(int $companyId, string $date, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM public_holidays WHERE company_id = ? AND holiday_date = ?';
        $params = [$companyId, $date];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    public function create(
        int $companyId,
        string $date,
        string $name,
        bool $gazetted = true,
        string $source = 'company',
        ?string $observedDate = null,
    ): int
    {
        $this->db
            ->prepare(
                'INSERT INTO public_holidays
                    (company_id, holiday_date, name, is_gazetted, source, observed_date)
                 VALUES (?, ?, ?, ?, ?, ?)',
            )
            ->execute([$companyId, $date, $name, $gazetted ? 1 : 0, $source, $observedDate]);

        return (int) $this->db->lastInsertId();
    }

    public function remove(int $companyId, int $holidayId): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM public_holidays WHERE id = ? AND company_id = ?',
        );
        $statement->execute([$holidayId, $companyId]);

        return $statement->rowCount() === 1;
    }
}
