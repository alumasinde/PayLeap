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
        $balances = $this->app->leave->requests->balances($companyId, (int) $employee['id'], $year);
        $available = 0.0;
        $taken = 0.0;

        foreach ($balances as $index => $balance) {
            $left = (float) $balance['days_per_year'] - (float) $balance['taken'] - (float) $balance['pending'];
            $balances[$index]['available'] = $left;
            $available += $left;
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
