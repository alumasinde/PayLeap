<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;

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

        $this->app->passwordResetService->request($email);

        // Always the same response, whether or not the address exists.
        $this->app->flash->add('success', 'If that email is on file, a temporary password is on its way.');

        $this->app->redirect('/forgot');
    }
}
