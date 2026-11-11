<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use DateTimeImmutable;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * Adds and removes company holidays. Dates are stored as real calendar days so
 * the roster and timesheet can match them without a recurrence rule.
 */
final class HolidayService
{
    public function __construct(
        private readonly HolidayRepository $holidays,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function add(int $companyId, string $date, string $name, int $actorId): int
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100) {
            throw new HttpException(422, 'Give the holiday a name of 1 to 100 characters.');
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new HttpException(422, 'Choose a valid holiday date.');
        }

        if ($this->holidays->dateExists($companyId, $date)) {
            throw new HttpException(422, 'That date is already a public holiday.');
        }

        $holidayId = $this->holidays->create($companyId, $date, $name);

        $this->auditLogger->record($companyId, $actorId, 'holiday.added', 'public_holiday', (string) $holidayId, [
            'date' => $date,
            'name' => $name,
        ]);

        return $holidayId;
    }

    public function remove(int $companyId, int $holidayId, int $actorId): void
    {
        $holiday = $this->holidays->find($companyId, $holidayId);

        if ($holiday === null) {
            throw new HttpException(404, 'That public holiday does not exist.');
        }

        if (!$this->holidays->remove($companyId, $holidayId)) {
            throw new HttpException(404, 'That public holiday does not exist.');
        }

        $this->auditLogger->record($companyId, $actorId, 'holiday.removed', 'public_holiday', (string) $holidayId, [
            'date' => $holiday['holiday_date'],
            'name' => $holiday['name'],
        ]);
    }
}
