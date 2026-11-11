<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use DateTimeImmutable;
use Mshahara\Payroll\App;
use Mshahara\Payroll\Modules\Attendance\AttendanceRepository;
use Mshahara\Payroll\Modules\Attendance\AttendanceService;
use Mshahara\Payroll\Support\HttpException;

/**
 * Attendance has two readings: the register for one day, and the timesheet for
 * one person's month. Both read the same attendance rows, so a clock captured
 * at a terminal and a correction saved by HR show up in exactly one place.
 */
final class AttendanceController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('attendance.view');
        $companyId = (int) $company['company_id'];

        $view = (string) $this->app->request->query('view', 'register');
        $view = $view === 'timesheet' ? 'timesheet' : 'register';
        $canEdit = $this->app->can('attendance.edit');
        $employees = $this->app->employees->register->activeForSelect($companyId);

        if ($view === 'timesheet') {
            $month = $this->month((string) $this->app->request->query('month', date('Y-m')));
            $employee = $this->selectedEmployee($companyId, (int) $this->app->request->query('employee_id', 0), $employees);
            $timesheet = $employee === null
                ? null
                : $this->app->attendance->timesheet->month($companyId, (int) $employee['id'], $month);

            echo $this->app->render('app/attendance/timesheet.twig', [
                'employees' => $employees,
                'selected_employee' => $employee,
                'month' => $month,
                'timesheet' => $timesheet,
                'can_edit' => $canEdit,
            ]);

            return;
        }

        $date = $this->date((string) $this->app->request->query('date', date('Y-m-d')));
        $rows = $this->app->attendance->records->day($companyId, $date);

        echo $this->app->render('app/attendance/index.twig', [
            'date' => $date,
            'rows' => $rows,
            'summary' => AttendanceService::summarise($rows),
            'statuses' => AttendanceRepository::STATUSES,
            'sources' => AttendanceRepository::SOURCES,
            'holiday' => $this->app->attendance->holidays->onDate($companyId, $date),
            'employees' => $canEdit ? $employees : [],
            'can_edit' => $canEdit,
        ]);
    }

    public function mark(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('attendance.edit');

        $date = (string) $this->app->request->string('work_date', date('Y-m-d'));

        try {
            $this->app->attendance->service->mark(
                (int) $company['company_id'],
                (int) $this->app->request->input('employee_id', 0),
                $date,
                $this->app->request->string('status'),
                $this->app->request->string('clock_in'),
                $this->app->request->string('clock_out'),
                (int) $this->app->user['id'],
                $this->app->request->string('source', 'manual'),
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/attendance?date=' . urlencode($date));
        }

        $this->app->flash->add('success', 'Attendance saved.');
        $this->app->redirect('/app/attendance?date=' . urlencode($date));
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

        $linked = $this->app->employees->register->forUser($companyId, (int) $this->app->user['id']);

        if ($linked !== null) {
            return $linked;
        }

        return $employees[0] ?? null;
    }

    private function month(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $value);

        if ($date === false || $date->format('Y-m') !== $value) {
            return date('Y-m');
        }

        return $value;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return date('Y-m-d');
        }

        return $value;
    }
}
