<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use DateTimeImmutable;

/** A simple month view of pending and approved leave for a team or company. */
final class LeavePlannerService
{
    public function __construct(private readonly LeaveRepository $requests)
    {
    }

    /** @return array<string,mixed> */
    public function month(int $companyId, int $year, int $month, ?array $scope = null): array
    {
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $end = $start->modify('last day of this month');
        $rows = $this->requests->planner(
            $companyId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $scope,
        );

        $byDate = [];

        foreach ($rows as $row) {
            $cursor = new DateTimeImmutable((string) $row['start_date']);
            $last = new DateTimeImmutable((string) $row['end_date']);

            while ($cursor <= $last) {
                $date = $cursor->format('Y-m-d');

                if ($date >= $start->format('Y-m-d') && $date <= $end->format('Y-m-d')) {
                    $byDate[$date][] = $row;
                }

                $cursor = $cursor->modify('+1 day');
            }
        }

        $days = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $date,
                'day' => $cursor->format('j'),
                'weekday' => $cursor->format('D'),
                'weekend' => (int) $cursor->format('N') >= 6,
                'leave' => $byDate[$date] ?? [],
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'year' => $year,
            'month' => $month,
            'label' => $start->format('F Y'),
            'rows' => $rows,
            'days' => $days,
        ];
    }
}
