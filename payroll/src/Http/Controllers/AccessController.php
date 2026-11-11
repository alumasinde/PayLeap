<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

final class AccessController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $companyId = (int) $company['company_id'];

        $canManage = $this->app->can('users.manage');
        $canInvite = $this->app->can('users.invite');

        if (!$canManage && !$canInvite) {
            $this->app->abort(403, 'Your role does not allow that.');
        }

        echo $this->app->render('app/access.twig', [
            'members' => $this->app->companyRepository->members($companyId),
            'roles' => $this->app->roleRepository->forCompany($companyId),
            'assigned' => $this->app->roleRepository->roleIdsByUser($companyId),
            'pending' => $canInvite ? $this->app->invitationService->pendingForCompany($companyId) : [],
            'can_manage' => $canManage,
            'can_invite' => $canInvite,
        ]);
    }

    public function invite(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('users.invite');

        $email = strtolower(trim($this->app->request->string('email')));
        $roleId = (int) $this->app->request->input('role_id', 0);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->app->flash->add('error', 'Enter a valid email address.');
            $this->app->redirect('/app/access');
        }

        $role = $this->app->roleRepository->find($roleId);

        if ($role === null || (int) $role['company_id'] !== (int) $company['company_id']) {
            $this->app->flash->add('error', 'Choose a role for this company.');
            $this->app->redirect('/app/access');
        }

        $this->app->invitationService->create(
            (int) $company['company_id'],
            $email,
            (string) $role['key'],
            (int) $this->app->user['id'],
        );

        $this->app->flash->add('success', 'Invitation sent to ' . $email . '.');
        $this->app->redirect('/app/access');
    }

    public function changeRole(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('users.manage');

        try {
            $this->app->membershipService->changeRole(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->request->input('role_id', 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/access');
        }

        $this->app->flash->add('success', 'Role updated.');
        $this->app->redirect('/app/access');
    }

    public function remove(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('users.manage');

        try {
            $this->app->membershipService->remove(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/access');
        }

        $this->app->flash->add('success', 'Access removed.');
        $this->app->redirect('/app/access');
    }

    public function revokeInvite(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('users.invite');

        try {
            $this->app->invitationService->revoke(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/access');
        }

        $this->app->flash->add('success', 'Invitation revoked.');
        $this->app->redirect('/app/access');
    }
}
