<?php

declare(strict_types=1);

use Mshahara\Payroll\Http\Controllers\AuthController;
use Mshahara\Payroll\Http\Controllers\InviteController;
use Mshahara\Payroll\Http\Controllers\PasswordController;
use Mshahara\Payroll\Http\Controllers\WorkspaceController;

/**
 * HTML routes: [method, path, [controller, action]].
 *
 * The product's API will live in routes/api.php, loaded separately so its
 * middleware (tokens, JSON errors) cannot leak into browser sessions.
 */
return [
    ['GET', '/', [WorkspaceController::class, 'root']],

    ['GET', '/login', [AuthController::class, 'showLogin']],
    ['POST', '/login', [AuthController::class, 'login']],
    ['POST', '/logout', [AuthController::class, 'logout']],

    ['GET', '/forgot', [PasswordController::class, 'showForgot']],
    ['POST', '/forgot', [PasswordController::class, 'send']],
    ['GET', '/reset/{token}', [PasswordController::class, 'showReset']],
    ['POST', '/reset/{token}', [PasswordController::class, 'reset']],

    ['GET', '/invite/{token}', [InviteController::class, 'show']],
    ['POST', '/invite/{token}', [InviteController::class, 'accept']],

    ['GET', '/app', [WorkspaceController::class, 'home']],
    ['GET', '/me', [WorkspaceController::class, 'me']],
    ['POST', '/me/sign-out-everywhere', [WorkspaceController::class, 'signOutEverywhere']],
    ['POST', '/app/company', [WorkspaceController::class, 'switchCompany']],
];
