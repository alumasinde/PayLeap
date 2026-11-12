<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Modules\Employees\EmployeeService;
use Mshahara\Payroll\Support\HttpException;
use Mshahara\Payroll\Support\Money;
use DateTimeImmutable;

/**
 * The employee register. Salary and bank fields are only read from the POST
 * when the role may see them, so a crafted request cannot set them.
 */
final class EmployeeController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.view');
        $companyId = (int) $company['company_id'];

        $status = (string) $this->app->request->query('status', 'active');

        if (!in_array($status, ['active', 'exited', 'all'], true)) {
            $status = 'active';
        }

        $filters = [
            'status' => $status,
            'department_id' => (int) $this->app->request->query('department_id', 0),
            'search' => trim((string) $this->app->request->query('search', '')),
        ];

        $total = $this->app->employees->register->count($companyId, $filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $this->app->request->query('page', 1)), $pages);
        $canSalary = $this->app->can('employees.salary.view');

        $summary = $this->app->employees->register->summary($companyId, $canSalary);

        echo $this->app->render('app/employees/index.twig', [
            'employees' => $this->app->employees->register->search(
                $companyId,
                $filters,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE,
            ),
            'departments' => $this->app->employees->departments->all($companyId),
            'summary' => $summary,
            'monthly_display' => number_format($summary['monthly_cents'] / 100, 2),
            'filters' => $filters,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'types' => EmployeeService::TYPES,
            'can_edit' => $this->app->can('employees.edit'),
            'can_salary' => $canSalary,
        ]);
    }

    public function show(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.view');

        $employee = $this->employee($company, (int) ($params['id'] ?? 0));

        echo $this->app->render('app/employees/show.twig', [
            'employee' => $employee,
            'types' => EmployeeService::TYPES,
            'genders' => EmployeeService::GENDERS,
            'marital_statuses' => EmployeeService::MARITAL_STATUSES,
            'next_of_kin' => $this->app->employees->register->nextOfKin((int) $company['company_id'], (int) $employee['id']),
            'dependants' => $this->app->employees->register->dependants((int) $company['company_id'], (int) $employee['id']),
            'education' => $this->app->employees->register->education((int) $company['company_id'], (int) $employee['id']),
            'reports' => $this->app->employees->register->directReports((int) $company['company_id'], (int) $employee['id']),
            'documents' => $this->app->documents->repository->forEmployee((int) $company['company_id'], (int) $employee['id']),
            'service_years' => $this->serviceYears($employee),
            'can_edit' => $this->app->can('employees.edit'),
            'can_salary' => $this->app->can('employees.salary.view'),
            'can_bank' => $this->app->can('employees.bank.view'),
            'leave_encashment' => $this->app->can('employees.edit')
                ? $this->encashmentQuote((int) $company['company_id'], (int) $employee['id'])
                : null,
        ]);
    }

    public function encashLeave(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');
        $employeeId = (int) ($params['id'] ?? 0);

        try {
            $this->app->leave->encashment->create(
                (int) $company['company_id'],
                $employeeId,
                date('Y-m-d'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/employees/' . $employeeId);
        }

        $this->app->flash->add('success', 'Leave encashment recorded for the next payroll run.');
        $this->app->redirect('/app/employees/' . $employeeId);
    }

    /** @return array<string,mixed>|null */
    private function encashmentQuote(int $companyId, int $employeeId): ?array
    {
        try {
            return $this->app->leave->encashment->quote($companyId, $employeeId, date('Y-m-d'));
        } catch (HttpException) {
            return null;
        }
    }

    public function photo(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.view');

        $employee = $this->employee($company, (int) ($params['id'] ?? 0));
        $path = $this->app->employees->files->absolute($employee['photo_path'] ?? null);

        if ($path === null) {
            $this->app->abort(404, 'That employee has no photo.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=300');
        readfile($path);
        exit;
    }

    public function create(): void
    {
        $this->app->requirePermission('employees.edit');

        $this->renderForm(null, []);
    }

    public function store(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $input = $this->payload();

        try {
            $employeeId = $this->app->employees->service->create(
                (int) $company['company_id'],
                $input,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            if ($error->status !== 422) {
                throw $error;
            }

            $this->renderForm(null, $input, $error->getMessage());

            return;
        }

        $this->app->flash->add('success', 'Employee added.');
        $this->app->redirect('/app/employees/' . $employeeId);
    }

    public function edit(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $employee = $this->employee($company, (int) ($params['id'] ?? 0));

        $this->renderForm($employee, []);
    }

    public function update(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $employee = $this->employee($company, (int) ($params['id'] ?? 0));
        $input = $this->payload();

        try {
            $this->app->employees->service->update(
                (int) $company['company_id'],
                (int) $employee['id'],
                $input,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            if ($error->status !== 422) {
                throw $error;
            }

            $this->renderForm($employee, $input, $error->getMessage());

            return;
        }

        $this->app->flash->add('success', 'Employee updated.');
        $this->app->redirect('/app/employees/' . $employee['id']);
    }

    public function archive(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $employeeId = (int) ($params['id'] ?? 0);

        try {
            $this->app->employees->service->archive(
                (int) $company['company_id'],
                $employeeId,
                $this->app->request->string('exit_date'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/employees/' . $employeeId);
        }

        try {
            $this->app->leave->encashment->create(
                (int) $company['company_id'],
                $employeeId,
                $this->app->request->string('exit_date') === ''
                    ? date('Y-m-d')
                    : $this->app->request->string('exit_date'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException) {
            // No untaken annual leave, no salary or no configured type: the
            // archive still succeeds and HR can record encashment separately.
        }

        $this->app->flash->add('success', 'Employee archived.');
        $this->app->redirect('/app/employees/' . $employeeId);
    }

    public function reinstate(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $employeeId = (int) ($params['id'] ?? 0);

        try {
            $this->app->employees->service->reinstate(
                (int) $company['company_id'],
                $employeeId,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/employees/' . $employeeId);
        }

        $this->app->flash->add('success', 'Employee reinstated.');
        $this->app->redirect('/app/employees/' . $employeeId);
    }

    public function addEducation(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $employee = $this->employee($company, (int) ($params['id'] ?? 0));

        try {
            $this->app->employees->service->addEducation(
                (int) $company['company_id'],
                (int) $employee['id'],
                $this->app->request->all(),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/employees/' . $employee['id']);
        }

        $this->app->flash->add('success', 'Education added.');
        $this->app->redirect('/app/employees/' . $employee['id']);
    }

    public function removeEducation(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('employees.edit');

        $employee = $this->employee($company, (int) ($params['id'] ?? 0));

        try {
            $this->app->employees->service->removeEducation(
                (int) $company['company_id'],
                (int) $employee['id'],
                (int) ($params['educationId'] ?? 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/employees/' . $employee['id']);
        }

        $this->app->flash->add('success', 'Education removed.');
        $this->app->redirect('/app/employees/' . $employee['id']);
    }

    /** @param array<string,mixed> $company */
    private function employee(array $company, int $employeeId): array
    {
        $employee = $this->app->employees->register->find((int) $company['company_id'], $employeeId);

        if ($employee === null) {
            $this->app->abort(404, 'That employee does not exist.');
        }

        return $employee;
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

    /**
     * Only fields the role may see are taken from the request.
     *
     * @return array<string,mixed>
     */
    private function payload(): array
    {
        $request = $this->app->request;
        $input = [];

        $fields = [
            'employee_no', 'first_name', 'middle_name', 'last_name', 'dob', 'gender',
            'email', 'phone', 'address', 'marital_status', 'nationality', 'cost_center',
            'national_id', 'kra_pin', 'nssf_number', 'shif_number',
            'department_id', 'manager_id', 'user_id', 'job_title', 'employment_type',
            'start_date', 'exit_date',
            'next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone',
            'next_of_kin_email', 'next_of_kin_address',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $input[$field] = $request->string($field);
            }
        }

        if ($request->has('remove_photo')) {
            $input['remove_photo'] = $request->string('remove_photo');
        }

        $photo = $request->file('photo');

        if ($photo !== null) {
            $input['photo_file'] = $photo;
        }

        if ($this->app->can('employees.salary.view') && $request->has('monthly_salary')) {
            $input['monthly_salary'] = $request->string('monthly_salary');
        }

        if ($this->app->can('employees.bank.view')) {
            foreach (['bank_name', 'bank_branch', 'bank_account'] as $field) {
                if ($request->has($field)) {
                    $input[$field] = $request->string($field);
                }
            }
        }

        return $input;
    }

    /** @param array<string,mixed> $values */
    private function renderForm(?array $employee, array $values, ?string $error = null): void
    {
        $company = $this->app->requireCompany();
        $cents = $employee['monthly_salary_cents'] ?? null;

        echo $this->app->render('app/employees/form.twig', [
            'employee' => $employee,
            'values' => $values,
            'error' => $error,
            'next_of_kin' => $employee === null
                ? null
                : $this->app->employees->register->nextOfKin((int) $company['company_id'], (int) $employee['id']),
            'departments' => $this->app->employees->departments->all((int) $company['company_id']),
            'managers' => $this->app->employees->register->activeForSelect(
                (int) $company['company_id'],
                $employee === null ? null : (int) $employee['id'],
            ),
            'portal_users' => $this->app->employees->register->portalUsers((int) $company['company_id']),
            'types' => EmployeeService::TYPES,
            'genders' => EmployeeService::GENDERS,
            'marital_statuses' => EmployeeService::MARITAL_STATUSES,
            'can_salary' => $this->app->can('employees.salary.view'),
            'can_bank' => $this->app->can('employees.bank.view'),
            'salary_display' => Money::format($cents === null ? null : (int) $cents),
        ], $error === null ? 200 : 422);
    }
}
