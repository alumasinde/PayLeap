<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\PasswordHasher;

final class InviteController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = $this->app->invitationService->find($token);

        if ($invite === null) {
            $this->app->abort(410, 'This invitation has expired or was already used.');
        }

        $existing = $this->app->invitationService->userForEmail((string) $invite['email']);

        echo $this->app->render('auth/invite.twig', [
            'invite' => $invite,
            'token' => $token,
            'existing' => $existing !== null,
            'role_label' => (string) $invite['role_name'],
        ]);
    }

    public function accept(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = $this->app->invitationService->find($token);

        if ($invite === null) {
            $this->app->abort(410, 'This invitation has expired or was already used.');
        }

        $existing = $this->app->invitationService->userForEmail((string) $invite['email']);

        if ($existing !== null) {
            if ($this->app->user === []) {
                $this->app->redirect('/login?next=' . urlencode('/invite/' . $token));
            }

            if ((int) $this->app->user['id'] !== (int) $existing['id']) {
                $this->app->abort(403, 'Sign in as ' . $invite['email'] . ' to accept this invitation.');
            }

            $this->app->invitationService->acceptExisting($invite, $this->app->user);

            $this->app->session->regenerate();
            $this->app->companyRepository->select((int) $invite['company_id']);
            $this->app->csrf->rotate();

            $this->app->flash->add('success', 'You have joined ' . $invite['company_name'] . '.');
            $this->app->redirect('/app');
        }

        $firstName = trim($this->app->request->string('first_name'));
        $lastName = trim($this->app->request->string('last_name'));
        $password = $this->app->request->string('password');
        $confirmation = $this->app->request->string('password_confirmation');

        $error = PasswordHasher::error($password, $confirmation);

        if ($firstName === '' || $lastName === '') {
            $error = 'Enter your first and last name.';
        } elseif (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            $error = 'That name is too long.';
        }

        if ($error !== null) {
            echo $this->app->render('auth/invite.twig', [
                'invite' => $invite,
                'token' => $token,
                'existing' => false,
                'role_label' => (string) $invite['role_name'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'error' => $error,
            ], 422);

            return;
        }

        $user = $this->app->invitationService->acceptNew($invite, $firstName, $lastName, $password);

        $this->app->authentication->startSession($user);
        $this->app->companyRepository->select((int) $invite['company_id']);
        $this->app->companyRepository->active((int) $user['id'], $this->app->companyRepository->membershipsFor((int) $user['id']));

        $this->app->flash->add('success', 'Welcome to ' . $invite['company_name'] . '.');
        $this->app->redirect('/app');
    }
}
