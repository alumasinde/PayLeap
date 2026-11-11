<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

/**
 * Leave requests and the approval decision. Viewing needs leave.view; deciding
 * needs leave.approve.team and is audited by the service.
 */
final class LeaveController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('leave.view');
        $companyId = (int) $company['company_id'];

        $status = (string) $this->app->request->query('status', 'pending');

        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $view = (string) $this->app->request->query('view', 'requests');
        $view = $view === 'balances' ? 'balances' : 'requests';
        $employees = $this->app->employees->register->activeForSelect($companyId);
        $employee = $this->selectedEmployee($companyId, (int) $this->app->request->query('employee_id', 0), $employees);
        $year = (int) $this->app->request->query('year', date('Y'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');

        echo $this->app->render('app/leave/index.twig', [
            'view' => $view,
            'requests' => $this->app->leave->requests->listForCompany($companyId, $status),
            'types' => $this->app->leave->requests->types($companyId),
            'filter_status' => $status,
            'pending_count' => $this->app->leave->requests->pendingCount($companyId),
            'can_approve' => $this->app->can('leave.approve.team'),
            'employees' => $employees,
            'selected_employee' => $employee,
            'year' => $year,
            'balances' => $view === 'balances' && $employee !== null
                ? $this->app->leave->requests->balances($companyId, (int) $employee['id'], $year)
                : [],
        ]);
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

    public function decide(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('leave.approve.team');
        $requestId = (int) ($params['id'] ?? 0);

        try {
            $this->app->leave->service->decide(
                (int) $company['company_id'],
                $requestId,
                $this->app->request->string('decision'),
                (int) $this->app->user['id'],
                $this->app->request->string('note'),
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/leave');
        }

        $this->app->flash->add('success', 'Leave request updated.');
        $this->app->redirect('/app/leave');
    }
}
