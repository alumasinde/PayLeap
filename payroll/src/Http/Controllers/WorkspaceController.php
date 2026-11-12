<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Authz\Portal;

final class WorkspaceController
{
    public function __construct(private readonly App $app)
    {
    }

    public function root(): void
    {
        $this->app->redirect($this->app->user === [] ? '/login' : $this->app->homePath());
    }

    public function home(): void
    {
        // A person whose role resolves to ESS belongs there, even if the old
        // /app bookmark still has workspace permissions behind it.
        if ($this->app->portal === Portal::ESS) {
            $this->app->redirect('/ess');
        }

        $this->renderHome();
    }

    public function hr(): void
    {
        $this->entry(Portal::HR);
    }

    public function finance(): void
    {
        $this->entry(Portal::FINANCE);
    }

    public function ess(): void
    {
        $company = $this->app->requireCompany();
        $this->app->portal = Portal::ESS;

        if ($this->app->employees->register->forUser((int) $company['company_id'], (int) $this->app->user['id']) === null) {
            $this->me();
            return;
        }

        $this->app->controller(SelfServiceController::class)->dashboard();
    }

    /** A portal is a shell; entering one still cannot widen what the role may do. */
    private function entry(Portal $portal): void
    {
        $this->app->requireCompany();

        if (!$this->app->portalAvailable($portal)) {
            $this->app->abort(403, 'That portal is not part of your role.');
        }

        $this->app->portal = $portal;
        $this->renderHome();
    }

    private function renderHome(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];

        $canEmployees = $this->app->can('employees.view');
        $canSalary = $this->app->can('employees.salary.view');
        $canPayroll = $this->app->can('payroll.view');
        $canApproveAllLeave = $this->app->can('leave.approve.all');
        $canApproveTeamLeave = $this->app->can('leave.approve.team');
        $canLeave = $this->app->can('leave.view') || $canApproveTeamLeave || $canApproveAllLeave;
        $canAttendance = $this->app->can('attendance.view');
        $canApproveLeave = $canApproveAllLeave || $canApproveTeamLeave;
        $leaveScope = $canLeave
            ? $this->app->leave->service->scopeFor(
                $companyId,
                (int) $this->app->user['id'],
                $canApproveAllLeave,
                $canApproveTeamLeave,
            )
            : null;

        $hasWorkspace = $canEmployees || $canPayroll || $canLeave || $canAttendance
            || $this->app->can('users.manage') || $this->app->can('users.invite')
            || $this->app->can('roles.manage') || $this->app->can('audit.view');

        if (!$hasWorkspace) {
            $this->app->redirect('/me');
        }

        $today = date('Y-m-d');
        $headcount = $this->app->dashboard->headcount($companyId, date('Y-m-01'), $canSalary);
        $attendance = $canAttendance ? $this->app->dashboard->attendanceOn($companyId, $today) : null;
        $onLeave = $canLeave ? $this->app->dashboard->onLeaveOn($companyId, $today) : 0;
        $pendingLeave = $canLeave ? $this->app->dashboard->pendingLeaveCount($companyId, $leaveScope) : 0;
        $draftRuns = $canPayroll ? $this->app->dashboard->draftRunCount($companyId) : 0;

        $present = $attendance === null ? 0 : $attendance['present'] + $attendance['late'];
        $attendancePercent = $headcount['active'] > 0
            ? (int) round($present / $headcount['active'] * 100)
            : 0;
        $unmarked = $attendance === null
            ? 0
            : max(0, $headcount['active'] - $present - $attendance['absent'] - $onLeave);

        $run = $canPayroll ? $this->app->dashboard->latestRun($companyId) : null;
        $statutory = $run === null ? null : $this->app->dashboard->statutoryForRun((int) $run['id']);

        $hour = (int) date('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

        echo $this->app->render('app/home.twig', [
            'greeting' => $greeting,
            'headcount' => $headcount,
            'attendance' => $attendance,
            'present' => $present,
            'attendance_percent' => $attendancePercent,
            'on_leave' => $onLeave,
            'unmarked' => $unmarked,
            'pending_leave' => $pendingLeave,
            'draft_runs' => $draftRuns,
            'pending_total' => $pendingLeave + $draftRuns,
            'run' => $run,
            'statutory' => $statutory,
            'departments' => $canEmployees ? $this->app->dashboard->departments($companyId, 6) : [],
            'recent_employees' => $canEmployees ? $this->app->dashboard->recentEmployees($companyId, 4) : [],
            'pending_requests' => $canLeave ? $this->app->dashboard->pendingLeaveList($companyId, 3, $leaveScope) : [],
            'actions' => $this->quickActions($canApproveLeave),
            'can_employees' => $canEmployees,
            'can_salary' => $canSalary,
            'can_payroll' => $canPayroll,
            'can_leave' => $canLeave,
            'can_attendance' => $canAttendance,
            'can_approve_leave' => $canApproveLeave,
            'can_run_payroll' => $this->app->can('payroll.run'),
            'can_edit_employees' => $this->app->can('employees.edit'),
        ]);
    }

    /**
     * Only the actions this role can actually complete.
     *
     * @return list<array{label:string,href:string,icon:string,note:string}>
     */
    private function quickActions(bool $canApproveLeave): array
    {
        $actions = [];

        if ($this->app->can('employees.edit')) {
            $actions[] = ['label' => 'Add employee', 'href' => '/app/employees/new', 'icon' => 'user-plus', 'note' => 'Grow the register'];
        }

        if ($this->app->can('payroll.run')) {
            $actions[] = ['label' => 'Prepare payroll', 'href' => '/app/payroll', 'icon' => 'wallet', 'note' => 'Start this month'];
        }

        if ($this->app->portal === Portal::FINANCE && $this->app->can('payroll.approve')) {
            $actions[] = ['label' => 'Review approvals', 'href' => '/app/payroll/approvals', 'icon' => 'check-circle', 'note' => 'Draft runs waiting'];
        }

        if ($this->app->can('attendance.edit')) {
            $actions[] = ['label' => 'Mark attendance', 'href' => '/app/attendance', 'icon' => 'clock', 'note' => 'Today\'s register'];
        }

        if ($canApproveLeave) {
            $actions[] = ['label' => 'Review leave', 'href' => '/app/leave', 'icon' => 'calendar', 'note' => 'Pending requests'];
        }

        if ($this->app->can('users.invite')) {
            $actions[] = ['label' => 'Invite user', 'href' => '/app/access', 'icon' => 'user-plus', 'note' => 'Give someone access'];
        }

        if ($this->app->can('payroll.view')) {
            $actions[] = ['label' => 'View payroll', 'href' => '/app/payroll', 'icon' => 'chart', 'note' => 'Runs and payslips'];
        }

        return array_slice($actions, 0, 4);
    }

    public function me(): void
    {
        $this->app->requireCompany();

        echo $this->app->render('me/home.twig');
    }

    public function signOutEverywhere(): void
    {
        $user = $this->app->requireUser();
        $companyId = $this->app->company === null ? null : (int) $this->app->company['company_id'];

        $this->app->auditLogger->record(
            $companyId,
            (int) $user['id'],
            'auth.signed_out_everywhere',
            'user',
            (string) $user['id'],
        );

        $this->app->authentication->logoutEverywhere((int) $user['id']);

        $this->app->redirect('/login?signed_out=everywhere');
    }

    public function switchCompany(): void
    {
        $user = $this->app->requireUser();
        $companyId = (int) $this->app->request->input('company_id', 0);
        $membership = $this->app->companyRepository->membership((int) $user['id'], $companyId);

        if ($membership === null) {
            $this->app->abort(404, 'Company not found.');
        }

        $this->app->session->regenerate();
        $this->app->companyRepository->select($companyId);
        $this->app->csrf->rotate();
        $this->app->resolveContext();

        $this->app->auditLogger->record($companyId, (int) $user['id'], 'company.switched', 'company', (string) $companyId);

        $this->app->redirect($this->app->homePath());
    }
}
