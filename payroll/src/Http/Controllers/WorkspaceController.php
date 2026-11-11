<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;

final class WorkspaceController
{
    public function __construct(private readonly App $app)
    {
    }

    public function root(): void
    {
        $this->app->redirect($this->app->user === [] ? '/login' : '/app');
    }

    public function home(): void
    {
        $company = $this->app->requireCompany();

        echo $this->app->render('app/home.twig', [
            'members' => $this->app->companies->members((int) $company['company_id']),
            'role_label' => $this->app->roleLabel((string) $company['role']),
        ]);
    }

    public function me(): void
    {
        $company = $this->app->requireCompany();

        echo $this->app->render('me/home.twig', [
            'role_label' => $this->app->roleLabel((string) $company['role']),
        ]);
    }

    public function signOutEverywhere(): void
    {
        $user = $this->app->requireUser();
        $companyId = $this->app->company === null ? null : (int) $this->app->company['company_id'];

        $this->app->audit->record(
            $companyId,
            (int) $user['id'],
            'auth.signed_out_everywhere',
            'user',
            (string) $user['id'],
        );

        $this->app->auth->logoutEverywhere((int) $user['id']);

        $this->app->redirect('/login?signed_out=everywhere');
    }

    public function switchCompany(): void
    {
        $user = $this->app->requireUser();
        $companyId = (int) $this->app->request->input('company_id', 0);
        $membership = $this->app->companies->membership((int) $user['id'], $companyId);

        if ($membership === null) {
            $this->app->abort(404, 'Company not found.');
        }

        $this->app->session->regenerate();
        $this->app->companies->select($companyId);
        $this->app->csrf->rotate();

        $this->app->audit->record($companyId, (int) $user['id'], 'company.switched', 'company', (string) $companyId);

        $this->app->redirect('/app');
    }
}
