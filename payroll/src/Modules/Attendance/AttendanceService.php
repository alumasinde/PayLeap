<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use DateTimeImmutable;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * Validates and records one day of attendance. The employee must be on this
 * company's register, and the date must be a real calendar date.
 */
final class AttendanceService
{
    public function __construct(
        private readonly AttendanceRepository $records,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function mark(
        int $companyId,
        int $employeeId,
        string $workDate,
        string $status,
        ?string $clockIn,
        ?string $clockOut,
        int $actorId,
        string $source = 'manual',
    ): void {
        if ($this->employees->find($companyId, $employeeId) === null) {
            throw new HttpException(422, 'Choose an employee from this company.');
        }

        $workDate = $this->date($workDate);

        if (!isset(AttendanceRepository::STATUSES[$status])) {
            throw new HttpException(422, 'Choose a valid attendance status.');
        }

        if (!isset(AttendanceRepository::SOURCES[$source])) {
            throw new HttpException(422, 'Choose a valid clock source.');
        }

        $clockIn = $this->time($clockIn, 'Clock-in');
        $clockOut = $this->time($clockOut, 'Clock-out');

        if ($clockIn !== null && $clockOut !== null && $clockOut < $clockIn) {
            throw new HttpException(422, 'Clock-out cannot be before clock-in.');
        }

        $this->records->mark($companyId, $employeeId, $workDate, $status, $clockIn, $clockOut, $actorId, null, $source);

        $this->auditLogger->record($companyId, $actorId, 'attendance.marked', 'employee', (string) $employeeId, [
            'date' => $workDate,
            'status' => $status,
            'source' => $source,
        ]);
    }

    /** Mark every active person still unmarked on a day as present. */
    public function markUnmarkedPresent(int $companyId, string $workDate, int $actorId): int
    {
        $workDate = $this->date($workDate);
        $marked = $this->records->markPresentForUnmarked($companyId, $workDate, $actorId);

        $this->auditLogger->record($companyId, $actorId, 'attendance.bulk_marked', 'company', (string) $companyId, [
            'date' => $workDate,
            'marked' => $marked,
        ]);

        return $marked;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{present:int,absent:int,on_leave:int,unmarked:int,total:int}
     */
    public static function summarise(array $rows): array
    {
        $summary = ['present' => 0, 'absent' => 0, 'on_leave' => 0, 'unmarked' => 0, 'total' => count($rows)];

        foreach ($rows as $row) {
            $status = (string) ($row['attendance_status'] ?? '');
            $onLeave = (int) ($row['on_leave'] ?? 0) === 1;

            if ($onLeave) {
                $summary['on_leave']++;
            }

            if ($status === 'present' || $status === 'late') {
                $summary['present']++;
            } elseif ($status === 'absent') {
                $summary['absent']++;
            } elseif ($status === '' && !$onLeave) {
                $summary['unmarked']++;
            }
        }

        return $summary;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'Choose a valid attendance date.');
        }

        return $value;
    }

    private function time(?string $value, string $label): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) !== 1) {
            throw new HttpException(422, $label . ' must be a time, for example 08:30.');
        }

        return $value . ':00';
    }
}
