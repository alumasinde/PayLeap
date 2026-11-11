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
    public function __construct(private readonly App $app)
    {
    }

    public function profile(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
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

        echo $this->app->render('me/attendance.twig', [
            'employee' => $employee,
            'month' => $month,
            'timesheet' => $this->app->attendance->timesheet->month($companyId, (int) $employee['id'], $month),
            'statuses' => AttendanceRepository::STATUSES,
            'sources' => AttendanceRepository::SOURCES,
        ]);
    }

    public function timesheet(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];
        $employee = $this->linkedEmployee($companyId);
        $month = $this->month((string) $this->app->request->query('month', date('Y-m')));

        echo $this->app->render('me/timesheet.twig', [
            'employee' => $employee,
            'month' => $month,
            'timesheet' => $this->app->attendance->timesheet->month($companyId, (int) $employee['id'], $month),
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

        $requests = $this->app->leave->requests->listForEmployee($companyId, (int) $employee['id']);
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
            $this->app->redirect('/me/leave');
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
