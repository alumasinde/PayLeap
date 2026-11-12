<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

/**
 * Turns a yearly leave entitlement into the days earned by a point in time.
 *
 * Monthly types (annual leave) earn one twelfth of the yearly entitlement for
 * each completed month of service in the leave year. Fixed types (sick,
 * maternity, paternity, compassionate) become available in full once their
 * waiting period has passed. The yearly entitlement is never the same thing as
 * the accrued balance, especially for a new joiner.
 */
final class LeaveAccrual
{
    private const MONTHLY = 'monthly';
    private const FIXED = 'fixed';

    /**
     * @param array<string,mixed> $type
     * @return array{entitled:float,accrued:float,monthly_rate:float,accrual_rule:string,accrual_start_months:int,months_accrued:int}
     */
    public static function calculate(
        array $type,
        ?string $employmentStart,
        int $year,
        ?string $asOf = null,
    ): array {
        $entitled = max(0.0, round((float) ($type['days_per_year'] ?? 0), 2));
        $monthlyRate = round($entitled / 12, 4);
        $rule = ($type['accrual_rule'] ?? self::FIXED) === self::MONTHLY ? self::MONTHLY : self::FIXED;
        $waitingMonths = max(0, min(24, (int) ($type['accrual_start_months'] ?? 0)));
        $empty = [
            'entitled' => $entitled,
            'accrued' => 0.0,
            'monthly_rate' => $monthlyRate,
            'accrual_rule' => $rule,
            'accrual_start_months' => $waitingMonths,
            'months_accrued' => 0,
        ];

        $start = self::date($employmentStart);
        $yearStart = new \DateTimeImmutable($year . '-01-01');
        $yearEnd = new \DateTimeImmutable($year . '-12-31');
        $point = self::date($asOf) ?? new \DateTimeImmutable('today');

        if ($point > $yearEnd) {
            $point = $yearEnd;
        }

        if ($start === null || $start > $yearEnd) {
            return $empty;
        }

        if ($point < $yearStart) {
            return $empty;
        }

        $eligibleFrom = $start->modify('+' . $waitingMonths . ' months');

        if ($point < $eligibleFrom) {
            return $empty;
        }

        if ($rule === self::FIXED) {
            $empty['accrued'] = $entitled;

            return $empty;
        }

        $accrualStart = $eligibleFrom > $yearStart ? $eligibleFrom : $yearStart;

        if ($point < $accrualStart) {
            return $empty;
        }

        $months = self::completedMonths($accrualStart, $point);

        // A completed leave year earns the whole entitlement, including the
        // final few days of December that are not a full calendar month yet.
        if ($accrualStart <= $yearStart && $point >= $yearEnd) {
            $months = 12;
        }

        $months = max(0, min(12, $months));
        $empty['months_accrued'] = $months;
        $empty['accrued'] = min($entitled, round($months * $monthlyRate, 2));

        return $empty;
    }

    private static function completedMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $months = ((int) $to->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $to->format('n') - (int) $from->format('n'));

        if ((int) $to->format('j') < (int) $from->format('j')) {
            $months--;
        }

        return max(0, $months);
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }
}