<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use DateTimeImmutable;
use Mshahara\Payroll\Modules\Leave\LeaveRepository;

/**
 * Turns one person's month of attendance and roster into timesheet rows:
 * worked, lost and overtime hours, plus the day and hour summaries.
 *
 * Worked time is clock-out minus clock-in; the roster supplies target hours
 * (shift length less the break). Leave overrides the roster for that day.
 */
final class TimesheetService
{
    public function __construct(
        private readonly AttendanceRepository $attendance,
        private readonly ShiftRepository $roster,
        private readonly LeaveRepository $leave,
    ) {
    }

    /** @return array<string,mixed> */
    public function month(int $companyId, int $employeeId, string $month): array
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month);
        $start = $start === false ? new DateTimeImmutable('first day of this month') : $start;
        $month = $start->format('Y-m');
        $from = $start->format('Y-m-d');
        $last = $start->modify('last day of this month');
        $to = $last->format('Y-m-d');

        $records = $this->attendance->forEmployeeMonth($companyId, $employeeId, $from, $to);
        $roster = $this->roster->forEmployeeBetween($companyId, $employeeId, $from, $to);
        $leave = $this->leave->approvedDates($companyId, $employeeId, $from, $to);

        $days = [
            'present' => 0,
            'absent' => 0,
            'off' => 0,
            'on_leave' => 0,
            'single_clock' => 0,
        ];
        $target = 0;
        $workedTotal = 0;
        $lostTotal = 0;
        $otTotal = 0;
        $scheduledDays = 0;
        $rows = [];

        $cursor = $start;

        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');
            $entry = $roster[$date] ?? null;
            $record = $records[$date] ?? null;
            $isLeave = isset($leave[$date]) || (($record['status'] ?? null) === 'leave');
            $isWeekend = (int) $cursor->format('N') >= 6;
            $isOff = !$isLeave && ($entry !== null ? $entry['roster_status'] === 'off' : $isWeekend);
            $scheduled = !$isLeave && $entry !== null && $entry['roster_status'] === 'shift';
            $isFuture = $date > date('Y-m-d');

            if ($isLeave) {
                $status = 'leave';
            } elseif ($record !== null) {
                $status = (string) $record['status'];
            } elseif ($isFuture) {
                $status = 'upcoming';
            } elseif ($isOff) {
                $status = 'off';
            } else {
                $status = 'unmarked';
            }

            $shiftMinutes = $scheduled ? self::shiftMinutes($entry) : 0;
            $clockIn = $record['clock_in'] ?? null;
            $clockOut = $record['clock_out'] ?? null;
            $in = self::minutes($clockIn);
            $out = self::minutes($clockOut);
            $singleClock = ($in === null) !== ($out === null);
            $worked = $in !== null && $out !== null ? self::span($in, $out) : 0;
            $lost = 0;
            $overtime = 0;

            if (!$isFuture && $scheduled && $status !== 'holiday') {
                $scheduledDays++;
                $target += $shiftMinutes;
                $lost = $status === 'absent' ? $shiftMinutes : max(0, $shiftMinutes - $worked);
                $overtime = max(0, $worked - $shiftMinutes);
            }

            $workedTotal += $worked;
            $lostTotal += $lost;
            $otTotal += $overtime;

            if ($status === 'present' || $status === 'late') {
                $singleClock ? $days['single_clock']++ : $days['present']++;
            } elseif ($status === 'absent') {
                $days['absent']++;
            } elseif ($status === 'leave') {
                $days['on_leave']++;
            } elseif ($status === 'off') {
                $days['off']++;
            }

            $rows[] = [
                'date' => $date,
                'day' => $cursor->format('D'),
                'day_num' => $cursor->format('j'),
                'shift' => $scheduled ? (string) $entry['shift_name'] : ($status === 'leave' ? 'Leave' : ($status === 'off' ? 'Off' : '—')),
                'shift_label' => $scheduled && $entry['start_time'] !== null
                    ? substr((string) $entry['start_time'], 0, 5) . ' – ' . substr((string) $entry['end_time'], 0, 5)
                    : '',
                'status' => $status,
                'clock_in' => $clockIn !== null ? substr((string) $clockIn, 0, 5) : '',
                'clock_out' => $clockOut !== null ? substr((string) $clockOut, 0, 5) : '',
                'worked' => $in !== null || $out !== null ? self::hours($worked) : '—',
                'lost' => !$isFuture && $scheduled && $status !== 'holiday' ? self::hours($lost) : '—',
                'ot' => !$isFuture && $scheduled && $status !== 'holiday' ? self::hours($overtime) : '—',
                'row_class' => match ($status) {
                    'leave' => 'row-leave',
                    'off' => 'row-off',
                    'absent' => 'row-absent',
                    'unmarked' => 'row-unmarked',
                    'upcoming' => 'row-upcoming',
                    default => '',
                },
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return [
            'month' => $month,
            'label' => $start->format('F Y'),
            'rows' => $rows,
            'scheduled_days' => $scheduledDays,
            'has_roster' => $roster !== [],
            'summary' => [
                'days' => $days,
                'target' => self::hours($target),
                'worked' => self::hours($workedTotal),
                'lost' => self::hours($lostTotal),
                'ot' => self::hours($otTotal),
                'target_minutes' => $target,
            ],
        ];
    }

    public static function hours(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** @param array<string,mixed> $shift */
    private static function shiftMinutes(array $shift): int
    {
        $start = self::minutes($shift['start_time'] ?? null);
        $end = self::minutes($shift['end_time'] ?? null);

        if ($start === null || $end === null) {
            return 0;
        }

        return max(0, self::span($start, $end) - (int) ($shift['break_minutes'] ?? 0));
    }

    private static function minutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hours * 60 + $minutes;
    }

    private static function span(int $from, int $to): int
    {
        return $to >= $from ? $to - $from : $to + 1440 - $from;
    }
}
