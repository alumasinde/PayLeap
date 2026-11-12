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
        $userId = (int) $this->app->user['id'];

        $status = (string) $this->app->request->query('status', 'pending');

        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $view = (string) $this->app->request->query('view', 'requests');
        $view = $view === 'balances' ? 'balances' : 'requests';

        $canApproveAll = $this->app->can('leave.approve.all');
        $canApproveTeam = $this->app->can('leave.approve.team');
        $scope = $this->app->leave->service->scopeFor($companyId, $userId, $canApproveAll, $canApproveTeam);

        $employees = $this->app->employees->register->activeForSelectInScope($companyId, $scope);
        $employee = $this->selectedEmployee($companyId, (int) $this->app->request->query('employee_id', 0), $employees);
        $year = (int) $this->app->request->query('year', date('Y'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');

        echo $this->app->render('app/leave/index.twig', [
            'view' => $view,
            'requests' => $this->app->leave->requests->listForCompany($companyId, $status, $scope),
            'types' => $this->app->leave->requests->types($companyId),
            'filter_status' => $status,
            'pending_count' => $this->app->leave->requests->pendingCount($companyId, $scope),
            'can_approve' => $canApproveAll || $canApproveTeam,
            'actor_employee_id' => $scope['employee_id'],
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
        $allowed = array_map(static fn (array $row): int => (int) $row['id'], $employees);

        if ($employeeId > 0) {
            if (!in_array($employeeId, $allowed, true)) {
                $this->app->abort(404, 'That employee is outside your leave scope.');
            }

            return $this->app->employees->register->find($companyId, $employeeId);
        }

        $self = $this->app->employees->register->forUser($companyId, (int) $this->app->user['id']);

        if ($self !== null && in_array((int) $self['id'], $allowed, true)) {
            return $self;
        }

        return $allowed === [] ? null : $this->app->employees->register->find($companyId, $allowed[0]);
    }

    public function decide(array $params): void
    {
        $company = $this->app->requireCompany();
        $canApproveAll = $this->app->can('leave.approve.all');
        $canApproveTeam = $this->app->can('leave.approve.team');

        if (!$canApproveAll && !$canApproveTeam) {
            $this->app->abort(403, 'Your role cannot decide leave.');
        }

        $requestId = (int) ($params['id'] ?? 0);
        $returnTo = $this->app->request->string('return_to') === 'ess'
            ? '/ess/leave/approvals'
            : '/app/leave';

        try {
            $this->app->leave->service->decide(
                (int) $company['company_id'],
                $requestId,
                $this->app->request->string('decision'),
                (int) $this->app->user['id'],
                $this->app->request->string('note'),
                $canApproveAll,
                $canApproveTeam,
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect($returnTo);
        }

        $this->app->flash->add('success', 'Leave request updated.');
        $this->app->redirect($returnTo);
    }
}
