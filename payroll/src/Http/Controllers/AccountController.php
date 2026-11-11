<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

final class AccountController
{
    public function __construct(private readonly App $app)
    {
    }

    public function updateProfile(): void
    {
        $user = $this->app->requireUser();
        $companyId = $this->app->company === null ? null : (int) $this->app->company['company_id'];

        try {
            $this->app->accountService->updateProfile(
                (int) $user['id'],
                $this->app->request->string('first_name'),
                $this->app->request->string('last_name'),
                $companyId,
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/me');
        }

        $this->app->flash->add('success', 'Your name was updated.');
        $this->app->redirect('/me');
    }

    public function changePassword(): void
    {
        $user = $this->app->requireUser();
        $companyId = $this->app->company === null ? null : (int) $this->app->company['company_id'];

        try {
            $epoch = $this->app->accountService->changePassword(
                (int) $user['id'],
                $this->app->request->string('current_password'),
                $this->app->request->string('password'),
                $this->app->request->string('password_confirmation'),
                $companyId,
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/me');
        }

        // Keep this device signed in; every other session has the old epoch.
        $this->app->authentication->refreshEpoch($epoch);

        $this->app->flash->add('success', 'Password changed. Other devices were signed out.');
        $this->app->redirect('/me');
    }
}
