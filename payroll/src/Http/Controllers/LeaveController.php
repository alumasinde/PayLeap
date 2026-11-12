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

        if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) {
            $status = 'pending';
        }

        $view = (string) $this->app->request->query('view', 'requests');
        $view = in_array($view, ['balances', 'planner'], true) ? $view : 'requests';

        $canApproveAll = $this->app->can('leave.approve.all');
        $canApproveTeam = $this->app->can('leave.approve.team');
        $scope = $this->app->leave->service->scopeFor($companyId, $userId, $canApproveAll, $canApproveTeam);

        $employees = $this->app->employees->register->activeForSelectInScope($companyId, $scope);
        $employee = $this->selectedEmployee($companyId, (int) $this->app->request->query('employee_id', 0), $employees);
        $year = (int) $this->app->request->query('year', date('Y'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');
        $month = (int) $this->app->request->query('month', date('n'));
        $month = $month >= 1 && $month <= 12 ? $month : (int) date('n');

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
            'month' => $month,
            'balances' => $view === 'balances' && $employee !== null
                ? $this->app->leave->balance->balances(
                    $companyId,
                    (int) $employee['id'],
                    $year,
                    $year < (int) date('Y') ? $year . '-12-31' : date('Y-m-d'),
                )
                : [],
            'planner' => $view === 'planner'
                ? $this->app->leave->planner->month($companyId, $year, $month, $scope)
                : null,
            'leave_settings' => $this->app->leave->settings->forCompany($companyId),
            'exceptions' => $view === 'balances'
                ? $this->app->leave->accrual->exceptions($companyId)
                : [],
            'statutory_rules' => $this->app->leave->statutory->all(),
        ]);
    }

    public function resolveException(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('leave.approve.all');

        try {
            $this->app->leave->accrual->resolveException(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                $this->app->request->string('action') === 'forfeit' ? 'forfeit' : 'retain',
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/leave?view=balances');
        }

        $this->app->flash->add('success', 'Leave carry-over review updated.');
        $this->app->redirect('/app/leave?view=balances');
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
