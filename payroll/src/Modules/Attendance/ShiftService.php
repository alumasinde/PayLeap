<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use DateTimeImmutable;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * Shift definitions and one roster day. A rest day carries no shift; a working
 * day must point at one of this company's shifts.
 */
final class ShiftService
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/';

    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function save(int $companyId, array $input, int $actorId): int
    {
        $shiftId = (int) ($input['id'] ?? 0);

        if ($shiftId > 0 && $this->shifts->find($companyId, $shiftId, true) === null) {
            throw new HttpException(404, 'That shift does not exist.');
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 80) {
            throw new HttpException(422, 'Give the shift a name of 1 to 80 characters.');
        }

        if ($this->shifts->nameExists($companyId, $name, $shiftId > 0 ? $shiftId : null)) {
            throw new HttpException(422, 'A shift with that name already exists.');
        }

        $start = $this->time($input['start_time'] ?? null);
        $end = $this->time($input['end_time'] ?? null);

        if ($start === null) {
            throw new HttpException(422, 'Enter a valid start time.');
        }

        if ($end === null) {
            throw new HttpException(422, 'Enter a valid end time.');
        }

        $startMinutes = self::minutes($start);
        $endMinutes = self::minutes($end);

        if ($startMinutes === $endMinutes) {
            throw new HttpException(422, 'The end time must differ from the start time.');
        }

        $span = $endMinutes > $startMinutes ? $endMinutes - $startMinutes : $endMinutes + 1440 - $startMinutes;

        $break = filter_var($input['break_minutes'] ?? 0, FILTER_VALIDATE_INT);

        if ($break === false || $break < 0 || $break > 480) {
            throw new HttpException(422, 'Break minutes must be between 0 and 480.');
        }

        if ($break >= $span) {
            throw new HttpException(422, 'The break must be shorter than the shift.');
        }

        $data = [
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'break_minutes' => $break,
            'active' => !empty($input['active']) ? 1 : 0,
        ];

        if ($shiftId > 0) {
            $this->shifts->update($companyId, $shiftId, $data);
        } else {
            $shiftId = $this->shifts->create($companyId, $data);
        }

        $this->auditLogger->record($companyId, $actorId, 'shift.saved', 'shift', (string) $shiftId, [
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return $shiftId;
    }

    public function setActive(int $companyId, int $shiftId, bool $active, int $actorId): void
    {
        if ($this->shifts->find($companyId, $shiftId, true) === null) {
            throw new HttpException(404, 'That shift does not exist.');
        }

        $this->shifts->setActive($companyId, $shiftId, $active);

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'shift.' . ($active ? 'enabled' : 'disabled'),
            'shift',
            (string) $shiftId,
        );
    }

    public function assign(
        int $companyId,
        int $employeeId,
        string $workDate,
        int $shiftId,
        int $actorId,
    ): void {
        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate);

        if ($date === false || $date->format('Y-m-d') !== $workDate) {
            throw new HttpException(422, 'Choose a valid roster date.');
        }

        if ($shiftId > 0) {
            if ($this->shifts->find($companyId, $shiftId) === null) {
                throw new HttpException(422, 'Choose a shift from this company.');
            }

            $this->shifts->upsert($companyId, $employeeId, $workDate, $shiftId, 'shift', $actorId);
            $entry = 'shift';
        } else {
            $this->shifts->upsert($companyId, $employeeId, $workDate, null, 'off', $actorId);
            $entry = 'off';
        }

        $this->auditLogger->record($companyId, $actorId, 'roster.updated', 'employee', (string) $employeeId, [
            'date' => $workDate,
            'entry' => $entry,
        ]);
    }

    /** Normalise 08:00 or 08:00:00 to H:i:s. */
    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match(self::TIME, $value) !== 1) {
            return null;
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
