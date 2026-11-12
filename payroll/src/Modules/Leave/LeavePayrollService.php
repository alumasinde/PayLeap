<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use DateTimeImmutable;
use Mshahara\Payroll\Modules\Attendance\HolidayRepository;
use Mshahara\Payroll\Modules\Attendance\TimesheetService;
use PDO;

/**
 * Converts approved leave and holiday work into payroll adjustments.
 * Unpaid leave reduces gross pay; the seven sick days at half pay are read from
 * leave_pay_tiers; public-holiday work earns the statutory premium and creates
 * TOIL at the same time.
 */
final class LeavePayrollService
{
    public function __construct(
        private readonly PDO $db,
        private readonly LeaveRepository $leave,
        private readonly LeaveSettingsRepository $settings,
        private readonly HolidayRepository $holidays,
        private readonly TimesheetService $timesheet,
    ) {
    }

    /**
     * @return array{leave_deduction_cents:int,holiday_premium_cents:int,toil_days:float,details:list<array<string,mixed>>}
     */
    public function adjust(
        int $companyId,
        int $employeeId,
        string $periodStart,
        string $periodEnd,
        int $baseSalaryCents,
    ): array {
        $settings = $this->settings->forCompany($companyId);
        $divisor = max(1, (int) $settings['daily_rate_divisor']);
        $dailyRate = (int) round($baseSalaryCents / $divisor);
        $holidayMultiplier = max(1.0, (float) $settings['holiday_work_multiplier']);
        $holidayMap = $this->holidays->between($companyId, $periodStart, $periodEnd);
        $requests = $this->leave->approvedForPeriod($companyId, $employeeId, $periodStart, $periodEnd);
        $sickType = null;

        foreach ($this->leave->types($companyId) as $type) {
            if ((string) ($type['system_code'] ?? '') === 'sick') {
                $sickType = $type;
                break;
            }
        }

        $priorSick = $sickType === null
            ? 0.0
            : $this->leave->approvedDaysBefore(
                $companyId,
                $employeeId,
                (int) $sickType['id'],
                substr($periodStart, 0, 4) . '-01-01',
                $periodStart,
            );
        $leaveDeduction = 0.0;
        $details = [];
        $sickConsumed = 0.0;

        foreach ($requests as $request) {
            $days = $this->daysInRange($request, $periodStart, $periodEnd, $holidayMap);

            if ($days <= 0.0001) {
                continue;
            }

            $paid = (int) ($request['paid'] ?? 1) === 1;
            $system = (string) ($request['system_code'] ?? '');
            $deduction = 0.0;

            if (!$paid) {
                $deduction = $dailyRate * $days;
            } elseif ($system === 'sick' && $sickType !== null) {
                $consumed = $sickConsumed + $this->sickDaysBeforePeriod($request, $periodStart, $holidayMap);
                $index = $priorSick + $consumed;
                $cursor = new DateTimeImmutable($periodStart);
                $last = new DateTimeImmutable($periodEnd);
                $thisRequest = 0.0;

                while ($cursor <= $last) {
                    $date = $cursor->format('Y-m-d');

                    if ($this->isCountedDay($date, (string) ($request['day_basis'] ?? 'working'), $holidayMap)) {
                        $index++;
                        $thisRequest++;
                        $percent = $this->tierPercent('sick', $index);

                        if ($percent < 100) {
                            $deduction += $dailyRate * (1 - $percent / 100);
                        }
                    }

                    $cursor = $cursor->modify('+1 day');
                }

                $sickConsumed += $thisRequest;
            }

            if ($deduction > 0.0001) {
                $leaveDeduction += $deduction;
                $details[] = [
                    'request_id' => (int) $request['id'],
                    'type' => (string) $request['leave_type'],
                    'days' => $days,
                    'amount_cents' => (int) round($deduction),
                    'reason' => $paid ? 'partial sick pay' : 'unpaid leave',
                ];
            }
        }

        $holidayPremium = 0;
        $toilDays = 0.0;
        $encashment = 0;
        $month = substr($periodStart, 0, 7);

        $encashmentStatement = $this->db->prepare(
            'SELECT COALESCE(SUM(amount_cents), 0) FROM leave_encashments
             WHERE company_id = ? AND employee_id = ? AND status = ? AND encashed_on <= ?',
        );
        $encashmentStatement->execute([$companyId, $employeeId, 'pending', $periodEnd]);
        $encashment = (int) $encashmentStatement->fetchColumn();

        if ($month === substr($periodEnd, 0, 7)) {
            $sheet = $this->timesheet->month($companyId, $employeeId, $month);

            foreach ($sheet['rows'] as $row) {
                if (empty($row['holiday']) || !is_string($row['holiday_hours']) || $row['holiday_hours'] === '—') {
                    continue;
                }

                $hours = $this->hoursValue($row['holiday_hours']);

                if ($hours <= 0) {
                    continue;
                }

                $hourlyCents = $baseSalaryCents / max(1, $divisor * 8);
                $holidayPremium += (int) round($hourlyCents * ($holidayMultiplier - 1) * $hours);
                $days = round($hours / 8, 2);
                $toilDays += $days;

                $this->db->prepare(
                    'INSERT INTO toil_entries (company_id, employee_id, work_date, hours, days, status)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE hours = VALUES(hours), days = VALUES(days)',
                )->execute([$companyId, $employeeId, (string) $row['date'], $hours, $days, 'pending']);

                $details[] = [
                    'date' => (string) $row['date'],
                    'type' => 'Public holiday work',
                    'hours' => $hours,
                    'toil_days' => $days,
                    'amount_cents' => (int) round($hourlyCents * ($holidayMultiplier - 1) * $hours),
                    'reason' => 'holiday premium',
                ];
            }
        }

        return [
            'leave_deduction_cents' => (int) round($leaveDeduction),
            'holiday_premium_cents' => $holidayPremium,
            'encashment_cents' => $encashment,
            'toil_days' => round($toilDays, 2),
            'details' => $details,
        ];
    }

    public function markEncashed(int $companyId, string $throughDate, int $runId): void
    {
        $this->db->prepare(
            'UPDATE leave_encashments
             SET status = ?, payroll_run_id = ?
             WHERE company_id = ? AND status = ? AND encashed_on <= ?',
        )->execute(['paid', $runId, $companyId, 'pending', $throughDate]);
    }

    /** @param array<string,mixed> $request */
    private function daysInRange(array $request, string $from, string $to, array $holidays): float
    {
        $start = new DateTimeImmutable((string) $request['start_date']);
        $end = new DateTimeImmutable((string) $request['end_date']);
        $fromDate = new DateTimeImmutable($from);
        $toDate = new DateTimeImmutable($to);

        if ($start < $fromDate) {
            $start = $fromDate;
        }

        if ($end > $toDate) {
            $end = $toDate;
        }

        $days = 0;
        $cursor = $start;

        while ($cursor <= $end) {
            if ($this->isCountedDay($cursor->format('Y-m-d'), (string) ($request['day_basis'] ?? 'working'), $holidays)) {
                $days++;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return (float) $days;
    }

    /** @param array<string,mixed> $request */
    private function sickDaysBeforePeriod(array $request, string $periodStart, array $holidays): float
    {
        $start = new DateTimeImmutable((string) $request['start_date']);
        $before = new DateTimeImmutable($periodStart);

        if ($start >= $before) {
            return 0.0;
        }

        $days = 0;
        $cursor = $start;

        while ($cursor < $before) {
            if ($this->isCountedDay($cursor->format('Y-m-d'), (string) ($request['day_basis'] ?? 'working'), $holidays)) {
                $days++;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return (float) $days;
    }

    private function isCountedDay(string $date, string $basis, array $holidays): bool
    {
        $day = new DateTimeImmutable($date);

        if ($basis === 'calendar') {
            return true;
        }

        return (int) $day->format('N') <= 5 && !isset($holidays[$date]);
    }

    private function tierPercent(string $systemCode, float $dayNumber): float
    {
        $statement = $this->db->prepare(
            'SELECT pay_percent FROM leave_pay_tiers
             WHERE country_code = ? AND system_code = ?
               AND from_day <= ? AND (to_day IS NULL OR to_day >= ?)
             ORDER BY from_day DESC LIMIT 1',
        );
        $statement->execute(['KE', $systemCode, $dayNumber, $dayNumber]);
        $percent = $statement->fetchColumn();

        return $percent === false ? 0.0 : (float) $percent;
    }

    private function hoursValue(string $value): float
    {
        if (!str_contains($value, ':')) {
            return max(0.0, (float) $value);
        }

        [$hours, $minutes] = array_map('intval', explode(':', $value));

        return round($hours + $minutes / 60, 2);
    }
}
