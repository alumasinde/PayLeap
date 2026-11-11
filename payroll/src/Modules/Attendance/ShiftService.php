<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use DateTimeImmutable;
use Mshahara\Payroll\Modules\Employees\EmployeeRepository;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * Assigns one roster day. A rest day carries no shift; a working day must
 * point at one of this company's shifts.
 */
final class ShiftService
{
    public function __construct(
        private readonly ShiftRepository $shifts,
        private readonly EmployeeRepository $employees,
        private readonly AuditLogger $auditLogger,
    ) {
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
}
