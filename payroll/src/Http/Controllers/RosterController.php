<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use DateTimeImmutable;
use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

/**
 * The month view of one person's roster: shift days, rest days and approved
 * leave. HR can set a single day from the same page.
 */
final class RosterController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('attendance.view');
        $companyId = (int) $company['company_id'];

        $month = $this->month((string) $this->app->request->query('month', date('Y-m')));
        $start = new DateTimeImmutable($month . '-01');
        $end = $start->modify('last day of this month');
        $employees = $this->app->employees->register->activeForSelect($companyId);
        $employee = $this->selectedEmployee($companyId, (int) $this->app->request->query('employee_id', 0), $employees);

        $roster = $employee === null
            ? []
            : $this->app->attendance->shifts->forEmployeeBetween($companyId, (int) $employee['id'], $start->format('Y-m-d'), $end->format('Y-m-d'));
        $leave = $employee === null
            ? []
            : $this->app->leave->requests->approvedDates($companyId, (int) $employee['id'], $start->format('Y-m-d'), $end->format('Y-m-d'));

        echo $this->app->render('app/roster/index.twig', [
            'employees' => $employees,
            'selected_employee' => $employee,
            'month' => $month,
            'label' => $start->format('F Y'),
            'prev_month' => $start->modify('-1 month')->format('Y-m'),
            'next_month' => $end->modify('+1 day')->format('Y-m'),
            'weeks' => $this->weeks($start, $end, $roster, $leave),
            'shifts' => $this->app->attendance->shifts->all($companyId),
            'can_edit' => $this->app->can('attendance.edit'),
        ]);
    }

    public function assign(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('attendance.edit');

        $month = $this->month((string) $this->app->request->string('month', date('Y-m')));
        $employeeId = (int) $this->app->request->input('employee_id', 0);
        $back = '/app/roster?month=' . urlencode($month) . '&employee_id=' . $employeeId;

        try {
            $this->app->attendance->roster->assign(
                (int) $company['company_id'],
                $employeeId,
                $this->app->request->string('work_date'),
                (int) $this->app->request->input('shift_id', 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect($back);
        }

        $this->app->flash->add('success', 'Roster updated.');
        $this->app->redirect($back);
    }

    /**
     * @param array<string,array<string,mixed>> $roster
     * @param array<string,true> $leave
     * @return list<list<array<string,mixed>>>
     */
    private function weeks(DateTimeImmutable $start, DateTimeImmutable $end, array $roster, array $leave): array
    {
        $cursor = $start->modify('-' . ((int) $start->format('N') - 1) . ' days');
        $last = $end->modify('+' . (7 - (int) $end->format('N')) . ' days');
        $month = $start->format('Y-m');
        $weeks = [];
        $week = [];

        while ($cursor <= $last) {
            $date = $cursor->format('Y-m-d');
            $entry = $roster[$date] ?? null;
            $isLeave = isset($leave[$date]);
            $weekend = (int) $cursor->format('N') >= 6;
            $shift = $entry !== null && $entry['roster_status'] === 'shift' ? $entry : null;
            $off = !$isLeave && ($entry !== null ? $entry['roster_status'] === 'off' : $weekend);

            $week[] = [
                'date' => $date,
                'day_num' => $cursor->format('j'),
                'in_month' => $cursor->format('Y-m') === $month,
                'is_today' => $date === date('Y-m-d'),
                'shift' => $isLeave ? null : $shift,
                'leave' => $isLeave,
                'off' => $off,
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $weeks;
    }

    /**
     * @param list<array<string,mixed>> $employees
     * @return array<string,mixed>|null
     */
    private function selectedEmployee(int $companyId, int $employeeId, array $employees): ?array
    {
        if ($employeeId > 0) {
            return $this->app->employees->register->find($companyId, $employeeId);
        }

        return $this->app->employees->register->forUser($companyId, (int) $this->app->user['id'])
            ?? $employees[0]
            ?? null;
    }

    private function month(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $value);

        if ($date === false || $date->format('Y-m') !== $value) {
            return date('Y-m');
        }

        return $value;
    }
}
