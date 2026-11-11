<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\PasswordHasher;

final class PasswordController
{
    public function __construct(private readonly App $app)
    {
    }

    public function showForgot(): void
    {
        echo $this->app->render('auth/forgot.twig');
    }

    public function send(): void
    {
        $email = strtolower(trim($this->app->request->string('email')));
        $ip = $this->app->request->ip();

        $allowed = $this->app->limiter->hit('forgot-ip', $ip, 10, 900)
            && $this->app->limiter->hit('forgot-account', $email, 3, 900);

        if (!$allowed) {
            echo $this->app->render('auth/forgot.twig', [
                'email' => $email,
                'error' => 'Too many requests. Wait a few minutes and try again.',
            ], 429);

            return;
        }

        $this->app->passwordResetService->request($email, $ip);

        // Always the same response, whether or not the address exists.
        $this->app->flash->add('success', 'If that email is on file, a reset link is on its way.');

        $this->app->redirect('/forgot');
    }

    public function showReset(array $params): void
    {
        $reset = $this->app->passwordResetService->find((string) ($params['token'] ?? ''));

        if ($reset === null) {
            $this->app->abort(410, 'This reset link has expired. Request a new one from the sign-in page.');
        }

        echo $this->app->render('auth/reset.twig', [
            'token' => $params['token'],
            'email' => $reset['email'],
        ]);
    }

    public function reset(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $reset = $this->app->passwordResetService->find($token);

        if ($reset === null) {
            $this->app->abort(410, 'This reset link has expired. Request a new one from the sign-in page.');
        }

        $password = $this->app->request->string('password');
        $confirmation = $this->app->request->string('password_confirmation');
        $error = PasswordHasher::error($password, $confirmation);

        if ($error !== null) {
            echo $this->app->render('auth/reset.twig', [
                'token' => $token,
                'email' => $reset['email'],
                'error' => $error,
            ], 422);

            return;
        }

        $this->app->passwordResetService->complete($reset, $password);

        $this->app->flash->add('success', 'Password updated. Sign in with the new one.');

        $this->app->redirect('/login');
    }
}
