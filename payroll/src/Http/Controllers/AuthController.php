<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;

final class AuthController
{
    public function __construct(private readonly App $app)
    {
    }

    public function showLogin(): void
    {
        if ($this->app->user !== []) {
            $this->app->redirect('/app');
        }

        echo $this->app->render('auth/login.twig', [
            'next' => $this->next(),
            'email' => '',
            'signed_out' => $this->app->request->query('signed_out') === 'everywhere',
        ]);
    }

    public function login(): void
    {
        $email = strtolower(trim($this->app->request->string('email')));
        $password = $this->app->request->string('password');
        $next = $this->next();
        $ip = $this->app->request->ip();

        $allowed = $this->app->limiter->hit('login-ip', $ip, 30, 900)
            && $this->app->limiter->hit('login-account', $email . '|' . $ip, 5, 900);

        if (!$allowed) {
            $this->app->audit->record(null, null, 'auth.rate_limited', 'user', null, ['email' => $email]);
            echo $this->app->render('auth/login.twig', [
                'next' => $next,
                'email' => $email,
                'error' => 'Too many attempts. Wait a few minutes and try again.',
            ], 429);

            return;
        }

        $user = $this->app->auth->attempt($email, $password);

        if ($user === null) {
            $this->app->audit->record(null, null, 'auth.login.failed', 'user', null, ['email' => $email]);
            echo $this->app->render('auth/login.twig', [
                'next' => $next,
                'email' => $email,
                'error' => 'Email or password is incorrect.',
            ], 422);

            return;
        }

        $this->app->auth->startSession($user);
        $this->app->limiter->clear('login-account', $email . '|' . $ip);

        $memberships = $this->app->companies->membershipsFor((int) $user['id']);
        $this->app->companies->active((int) $user['id'], $memberships);

        $this->app->audit->record(null, (int) $user['id'], 'auth.login', 'user', (string) $user['id']);

        $this->app->redirect($next ?? '/app');
    }

    public function logout(): void
    {
        $userId = (int) ($this->app->user['id'] ?? 0);

        if ($userId > 0) {
            $this->app->audit->record(null, $userId, 'auth.logout', 'user', (string) $userId);
        }

        $this->app->auth->logout();
        $this->app->redirect('/login');
    }

    private function next(): ?string
    {
        $next = $this->app->request->input('next') ?? $this->app->request->query('next');

        if (!is_string($next)) {
            return null;
        }

        $next = trim($next);

        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, ':')) {
            return null;
        }

        return $next;
    }
}
