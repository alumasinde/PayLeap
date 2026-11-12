<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use DateTimeImmutable;
use Mshahara\Payroll\App;
use Mshahara\Payroll\Modules\Attendance\AttendanceRepository;
use Mshahara\Payroll\Modules\Employees\EmployeeService;
use Mshahara\Payroll\Support\HttpException;

/**
 * The employee portal. Everything here is scoped to the employee record linked
 * to the signed-in login, so the pages never accept an employee id from the
 * browser and never expose another person's record.
 */
final class SelfServiceController
{
    private const LEAVE_ROWS = 10;
    private const MONTH_ROWS = 15;

    public function __construct(private readonly App $app)
    {
    }

    public function profile(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];

        if ($this->app->employees->register->forUser($companyId, (int) $this->app->user['id']) === null) {
            $this->app->redirect('/me/account');
        }

        $employee = $this->linkedEmployee($companyId);

        echo $this->app->render('me/profile.twig', [
            'employee' => $employee,
            'types' => EmployeeService::TYPES,
            'genders' => EmployeeService::GENDERS,
            'marital_statuses' => EmployeeService::MARITAL_STATUSES,
            'next_of_kin' => $this->app->employees->register->nextOfKin($companyId, (int) $employee['id']),
            'dependants' => $this->app->employees->register->dependants($companyId, (int) $employee['id']),
            'education' => $this->app->employees->register->education($companyId, (int) $employee['id']),
            'reports' => $this->app->employees->register->directReports($companyId, (int) $employee['id']),
            'documents' => $this->app->documents->repository->forEmployee($companyId, (int) $employee['id']),
            'service_years' => $this->serviceYears($employee),
        ]);
    }

    /** The employee portal home: a summary of the person's own records. */
    public function dashboard(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];

        if ($this->app->employees->register->forUser($companyId, (int) $this->app->user['id']) === null) {
            $this->app->redirect('/me/account');
        }

        $employee = $this->linkedEmployee($companyId);
        $year = (int) date('Y');
        $month = date('Y-m');
        $leaveBalances = $this->app->leave->balance->balances($companyId, (int) $employee['id'], $year);
        $leaveAvailable = 0.0;
        $leaveTaken = 0.0;
        $leavePending = 0.0;
        $leaveAccrued = 0.0;
        $leaveEntitled = 0.0;
        $annualBalance = null;

        foreach ($leaveBalances as $index => $balance) {
            $available = max(0.0, (float) $balance['available']);
            $leaveBalances[$index]['available'] = $available;
            $leaveAvailable += $available;
            $leaveTaken += (float) $balance['taken'];
            $leavePending += (float) $balance['pending'];
            $leaveAccrued += (float) $balance['accrued'];
            $leaveEntitled += (float) $balance['entitled'];

            if ($balance['name'] === 'Annual leave') {
                $annualBalance = $leaveBalances[$index];
            }
        }

        if ($annualBalance !== null) {
            $leaveAvailable = (float) $annualBalance['available'];
            $leaveTaken = (float) $annualBalance['taken'];
            $leavePending = (float) $annualBalance['pending'];
            $leaveAccrued = (float) $annualBalance['accrued'];
            $leaveEntitled = (float) $annualBalance['entitled'];
        }

        $timesheet = $this->app->attendance->timesheet->month($companyId, (int) $employee['id'], $month);
        $days = $timesheet['summary']['days'];
        $scheduledDays = (int) $timesheet['scheduled_days'];
        $presentDays = (int) $days['present'];
        $absentDays = (int) $days['absent'];
        $attendanceBase = $presentDays + $absentDays;
        $attendanceRate = $attendanceBase > 0
            ? (int) round($presentDays / $attendanceBase * 100)
            : 0;
        $chartBuckets = [];

        foreach ($timesheet['rows'] as $row) {
            $week = (int) ceil(((int) substr((string) $row['date'], 8, 2)) / 7);
            $chartBuckets[$week] ??= ['label' => 'W' . $week, 'present' => 0, 'absent' => 0];

            if (in_array($row['status'], ['present', 'late'], true)) {
                $chartBuckets[$week]['present']++;
            } elseif ($row['status'] === 'absent') {
                $chartBuckets[$week]['absent']++;
            }
        }

        ksort($chartBuckets);
        $chartMax = 1;

        foreach ($chartBuckets as $bucket) {
            $chartMax = max($chartMax, $bucket['present'], $bucket['absent']);
        }

        $attendanceChart = [];
        $chartIndex = 0;

        foreach ($chartBuckets as $bucket) {
            $presentHeight = (int) round($bucket['present'] / $chartMax * 120);
            $absentHeight = (int) round($bucket['absent'] / $chartMax * 120);
            $x = 54 + $chartIndex * 112;
            $attendanceChart[] = [
                'label' => $bucket['label'],
                'present' => $bucket['present'],
                'absent' => $bucket['absent'],
                'x' => $x,
                'present_y' => 170 - $presentHeight,
                'present_h' => $presentHeight,
                'absent_x' => $x + 34,
                'absent_y' => 170 - $absentHeight,
                'absent_h' => $absentHeight,
                'label_x' => $x + 32,
            ];
            $chartIndex++;
        }

        $attendanceChartHasData = false;

        foreach ($attendanceChart as $week) {
            if ($week['present'] > 0 || $week['absent'] > 0) {
                $attendanceChartHasData = true;
                break;
            }
        }
        $payslips = $this->app->payroll->runs->payslipsForEmployee($companyId, (int) $this->app->user['id']);
        $announcements = $this->app->announcements->repository->latest($companyId, 2);
        $requests = $this->app->leave->requests->listForEmployee($companyId, (int) $employee['id'], 100);
        $pendingRequests = count(array_filter(
            $requests,
            static fn (array $request): bool => $request['status'] === 'pending',
        ));
        $canApproveAll = $this->app->can('leave.approve.all');
        $canApproveTeam = $this->app->can('leave.approve.team');
        $teamPending = 0;

        if ($canApproveAll || $canApproveTeam) {
            $scope = $this->app->leave->service->scopeFor(
                $companyId,
                (int) $this->app->user['id'],
                $canApproveAll,
                $canApproveTeam,
            );
            $teamPending = $this->app->leave->requests->pendingCount($companyId, $scope);
        }

        $leaveChartTotal = $leaveAvailable + $leavePending + $leaveTaken;
        $leaveChart = [];
        $leaveOffset = 0.0;

        foreach ([
            ['label' => 'Available', 'key' => 'available', 'value' => $leaveAvailable],
            ['label' => 'Pending', 'key' => 'pending', 'value' => $leavePending],
            ['label' => 'Taken', 'key' => 'taken', 'value' => $leaveTaken],
        ] as $part) {
            $percent = $leaveChartTotal > 0 ? ($part['value'] / $leaveChartTotal * 100) : 0.0;
            $leaveChart[] = [
                'label' => $part['label'],
                'key' => $part['key'],
                'value' => $part['value'],
                'percent' => round($percent, 2),
                'offset' => round($leaveOffset, 2),
            ];
            $leaveOffset += $percent;
        }

        $activities = [];

        foreach (array_slice($payslips, 0, 3) as $payslip) {
            $period = strtotime((string) $payslip['period'] . '-01');
            $activities[] = [
                'date' => (string) ($payslip['approved_at'] ?: $payslip['period'] . '-01'),
                'icon' => 'file',
                'tone' => 'approved',
                'title' => 'Payslip available',
                'status' => null,
                'detail' => ($period === false ? (string) $payslip['period'] : date('F Y', $period)) . ' payslip is ready to download.',
            ];
        }

        foreach (array_slice($requests, 0, 3) as $request) {
            $status = (string) $request['status'];
            $start = strtotime((string) $request['start_date']);
            $end = strtotime((string) $request['end_date']);
            $activities[] = [
                'date' => (string) ($request['decided_at'] ?: $request['created_at']),
                'icon' => $status === 'approved' ? 'check-circle' : ($status === 'rejected' ? 'alert-circle' : 'clock'),
                'tone' => $status,
                'title' => $status === 'approved' ? 'Leave approved' : ($status === 'rejected' ? 'Leave rejected' : 'Leave request sent'),
                'status' => $status,
                'detail' => $request['leave_type'] . ' · ' . ($start === false ? (string) $request['start_date'] : date('j M', $start)) . ' – ' . ($end === false ? (string) $request['end_date'] : date('j M Y', $end)),
            ];
        }

        usort($activities, static fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        $hour = (int) date('G');

        echo $this->app->render('me/dashboard.twig', [
            'employee' => $employee,
            'greeting' => $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'),
            'today' => date('Y-m-d'),
            'year' => $year,
            'month_label' => $timesheet['label'],
            'scheduled_days' => $scheduledDays,
            'attendance_rate' => $attendanceRate,
            'attendance_days' => $days,
            'attendance_chart' => $attendanceChart,
            'attendance_chart_has_data' => $attendanceChartHasData,
            'leave_balances' => $leaveBalances,
            'leave_available' => $leaveAvailable,
            'leave_accrued' => $leaveAccrued,
            'leave_entitled' => $leaveEntitled,
            'leave_taken' => $leaveTaken,
            'leave_pending' => $leavePending,
            'leave_primary_label' => $annualBalance !== null ? $annualBalance['name'] : 'Leave',
            'leave_chart' => $leaveChart,
            'pending_requests' => $pendingRequests,
            'payslip_count' => count($payslips),
            'latest_payslip' => $payslips[0] ?? null,
            'announcements' => $announcements,
            'activities' => array_slice($activities, 0, 4),
            'can_approve_team' => $canApproveAll || $canApproveTeam,
            'team_pending' => $teamPending,
        ]);
    }

    public function attendance(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->linkedEmployee($companyId);
        $month = $this->month((string) $this->app->request->query('month', date('Y-m')));
        $timesheet = $this->app->attendance->timesheet->month($companyId, (int) $employee['id'], $month);
        $totalRows = count($timesheet['rows']);
        [$page, $pages] = $this->pager($totalRows, self::MONTH_ROWS);
        $timesheet['rows'] = array_slice($timesheet['rows'], ($page - 1) * self::MONTH_ROWS, self::MONTH_ROWS);

        echo $this->app->render('me/attendance.twig', [
            'employee' => $employee,
            'month' => $month,
            'timesheet' => $timesheet,
            'statuses' => AttendanceRepository::STATUSES,
            'sources' => AttendanceRepository::SOURCES,
            'page' => $page,
            'pages' => $pages,
            'total_rows' => $totalRows,
        ]);
    }

    public function timesheet(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->linkedEmployee($companyId);
        $month = $this->month((string) $this->app->request->query('month', date('Y-m')));
        $timesheet = $this->app->attendance->timesheet->month($companyId, (int) $employee['id'], $month);
        $totalRows = count($timesheet['rows']);
        [$page, $pages] = $this->pager($totalRows, self::MONTH_ROWS);
        $timesheet['rows'] = array_slice($timesheet['rows'], ($page - 1) * self::MONTH_ROWS, self::MONTH_ROWS);

        echo $this->app->render('me/timesheet.twig', [
            'employee' => $employee,
            'month' => $month,
            'timesheet' => $timesheet,
            'page' => $page,
            'pages' => $pages,
            'total_rows' => $totalRows,
        ]);
    }

    public function leave(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->linkedEmployee($companyId);
        $year = (int) date('Y');
        $balances = $this->app->leave->balance->balances($companyId, (int) $employee['id'], $year);
        $available = 0.0;
        $taken = 0.0;

        foreach ($balances as $index => $balance) {
            $left = (float) $balance['available'];
            $balances[$index]['available'] = $left;
            $available += max(0.0, $left);
            $taken += (float) $balance['taken'];
        }

        $total = $this->app->leave->requests->countForEmployee($companyId, (int) $employee['id']);
        [$page, $pages] = $this->pager($total, self::LEAVE_ROWS);
        $requests = $this->app->leave->requests->listForEmployee(
            $companyId,
            (int) $employee['id'],
            self::LEAVE_ROWS,
            ($page - 1) * self::LEAVE_ROWS,
        );
        $pending = 0;

        foreach ($requests as $request) {
            if ($request['status'] === 'pending') {
                $pending++;
            }
        }

        echo $this->app->render('me/leave.twig', [
            'employee' => $employee,
            'year' => $year,
            'balances' => $balances,
            'requests' => $requests,
            'available_days' => $available,
            'taken_days' => $taken,
            'pending_count' => $pending,
            'total_requests' => $total,
            'page' => $page,
            'pages' => $pages,
            'open_apply' => $this->app->request->query('apply', '') === '1',
        ]);
    }

    /** Departmental (or, with leave.approve.all, company-wide) approvals in ESS. */
    public function teamLeave(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $canApproveAll = $this->app->can('leave.approve.all');
        $canApproveTeam = $this->app->can('leave.approve.team');

        if (!$canApproveAll && !$canApproveTeam) {
            $this->app->abort(403, 'Your role cannot decide leave.');
        }

        $scope = $this->app->leave->service->scopeFor(
            $companyId,
            (int) $this->app->user['id'],
            $canApproveAll,
            $canApproveTeam,
        );

        echo $this->app->render('me/leave_approvals.twig', [
            'requests' => $this->app->leave->requests->listForCompany($companyId, 'pending', $scope),
            'pending_count' => $this->app->leave->requests->pendingCount($companyId, $scope),
            'can_approve_all' => $canApproveAll,
        ]);
    }

    public function submitLeave(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->linkedEmployee($companyId);

        try {
            $this->app->leave->service->submit(
                $companyId,
                (int) $employee['id'],
                (int) $this->app->request->input('leave_type_id', 0),
                $this->app->request->string('start_date'),
                $this->app->request->string('end_date'),
                $this->app->request->string('reason'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/me/leave?apply=1');
        }

        $this->app->flash->add('success', 'Leave request sent for approval.');
        $this->app->redirect('/me/leave');
    }

    public function cancelLeave(array $params): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->linkedEmployee($companyId);
        $request = $this->app->leave->requests->find($companyId, (int) ($params['id'] ?? 0));

        if ($request === null || (int) $request['employee_id'] !== (int) $employee['id']) {
            $this->app->abort(404, 'That leave request does not exist.');
        }

        try {
            $this->app->leave->service->cancel(
                $companyId,
                (int) $request['id'],
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/me/leave');
        }

        $this->app->flash->add('success', 'Leave request cancelled.');
        $this->app->redirect('/me/leave');
    }

    /** @return array<string,mixed> */
    private function linkedEmployee(int $companyId): array
    {
        $linked = $this->app->employees->register->forUser($companyId, (int) $this->app->user['id']);

        if ($linked === null) {
            $this->app->abort(403, 'Your login is not linked to an employee record yet. Ask HR to link it.');
        }

        $employee = $this->app->employees->register->find($companyId, (int) $linked['id']);

        if ($employee === null) {
            $this->app->abort(404, 'That employee record does not exist.');
        }

        return $employee;
    }

    /** @return array{0:int,1:int} page number and total pages, clamped to the data */
    private function pager(int $total, int $perPage): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $this->app->request->query('page', '1')));

        return [$page, $pages];
    }

    private function month(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $value);

        if ($date === false || $date->format('Y-m') !== $value) {
            return date('Y-m');
        }

        return $value;
    }

    /** @param array<string,mixed> $employee */
    private function serviceYears(array $employee): string
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $employee['start_date']);

        if ($start === false) {
            return '—';
        }

        $end = $employee['exit_date']
            ? DateTimeImmutable::createFromFormat('!Y-m-d', (string) $employee['exit_date'])
            : new DateTimeImmutable('today');
        $end = $end === false ? new DateTimeImmutable('today') : $end;
        $span = $start->diff($end);

        return $span->y . ' yrs ' . $span->m . ' mos';
    }
}
