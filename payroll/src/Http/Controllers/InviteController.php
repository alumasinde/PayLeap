<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\Passwords;

final class InviteController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = $this->app->invitations->find($token);

        if ($invite === null) {
            $this->app->abort(410, 'This invitation has expired or was already used.');
        }

        $existing = $this->findUser((string) $invite['email']);

        echo $this->app->render('auth/invite.twig', [
            'invite' => $invite,
            'token' => $token,
            'existing' => $existing !== null,
            'role_label' => $this->app->roleLabel((string) $invite['role']),
        ]);
    }

    public function accept(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $invite = $this->app->invitations->find($token);

        if ($invite === null) {
            $this->app->abort(410, 'This invitation has expired or was already used.');
        }

        $existing = $this->findUser((string) $invite['email']);

        if ($existing !== null) {
            if ($this->app->user === []) {
                $this->app->redirect('/login?next=' . urlencode('/invite/' . $token));
            }

            if ((int) $this->app->user['id'] !== (int) $existing['id']) {
                $this->app->abort(403, 'Sign in as ' . $invite['email'] . ' to accept this invitation.');
            }

            $this->app->invitations->acceptExisting($invite, $this->app->user);

            $this->app->session->regenerate();
            $this->app->companies->select((int) $invite['company_id']);
            $this->app->csrf->rotate();

            $this->app->flash->add('success', 'You have joined ' . $invite['company_name'] . '.');
            $this->app->redirect('/app');
        }

        $name = trim($this->app->request->string('name'));
        $password = $this->app->request->string('password');
        $confirmation = $this->app->request->string('password_confirmation');

        $error = Passwords::error($password, $confirmation);

        if ($name === '') {
            $error = 'Enter your full name.';
        } elseif (mb_strlen($name) > 120) {
            $error = 'That name is too long.';
        }

        if ($error !== null) {
            echo $this->app->render('auth/invite.twig', [
                'invite' => $invite,
                'token' => $token,
                'existing' => false,
                'role_label' => $this->app->roleLabel((string) $invite['role']),
                'name' => $name,
                'error' => $error,
            ], 422);

            return;
        }

        $user = $this->app->invitations->acceptNew($invite, $name, $password);

        $this->app->auth->startSession($user);
        $this->app->companies->select((int) $invite['company_id']);
        $this->app->companies->active((int) $user['id'], $this->app->companies->membershipsFor((int) $user['id']));

        $this->app->flash->add('success', 'Welcome to ' . $invite['company_name'] . '.');
        $this->app->redirect('/app');
    }

    /** @return array<string,mixed>|null */
    private function findUser(string $email): ?array
    {
        $statement = $this->app->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([strtolower($email)]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }
}
