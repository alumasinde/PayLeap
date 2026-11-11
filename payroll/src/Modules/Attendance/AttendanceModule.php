<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

final class AttendanceModule
{
    public function __construct(
        public readonly AttendanceRepository $records,
        public readonly AttendanceService $service,
        public readonly ShiftRepository $shifts,
        public readonly ShiftService $roster,
        public readonly TimesheetService $timesheet,
        public readonly HolidayRepository $holidays,
        public readonly HolidayService $holidayService,
        public readonly DeviceRepository $devices,
        public readonly DeviceService $deviceService,
    ) {
    }
}
