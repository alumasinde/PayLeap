<?php

declare(strict_types=1);

use Mshahara\Payroll\Http\Controllers\AccessController;
use Mshahara\Payroll\Http\Controllers\AccountController;
use Mshahara\Payroll\Http\Controllers\AuthController;
use Mshahara\Payroll\Http\Controllers\EmployeeController;
use Mshahara\Payroll\Http\Controllers\EmployeeDepartmentController;
use Mshahara\Payroll\Http\Controllers\InviteController;
use Mshahara\Payroll\Http\Controllers\PasswordController;
use Mshahara\Payroll\Http\Controllers\PayrollController;
use Mshahara\Payroll\Http\Controllers\RoleController;
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
    ['GET', '/app/access', [AccessController::class, 'index']],
    ['POST', '/app/access/invite', [AccessController::class, 'invite']],
    ['POST', '/app/access/members/{id}/role', [AccessController::class, 'changeRole']],
    ['POST', '/app/access/members/{id}/remove', [AccessController::class, 'remove']],
    ['POST', '/app/access/invites/{id}/revoke', [AccessController::class, 'revokeInvite']],
    ['GET', '/app/access/roles/{id}', [RoleController::class, 'edit']],
    ['POST', '/app/access/roles/{id}', [RoleController::class, 'update']],

    ['GET', '/app/employees', [EmployeeController::class, 'index']],
    ['GET', '/app/employees/new', [EmployeeController::class, 'create']],
    ['POST', '/app/employees', [EmployeeController::class, 'store']],
    ['POST', '/app/employees/departments', [EmployeeDepartmentController::class, 'store']],
    ['GET', '/app/employees/{id}', [EmployeeController::class, 'show']],
    ['GET', '/app/employees/{id}/edit', [EmployeeController::class, 'edit']],
    ['POST', '/app/employees/{id}', [EmployeeController::class, 'update']],
    ['POST', '/app/employees/{id}/archive', [EmployeeController::class, 'archive']],
    ['POST', '/app/employees/{id}/reinstate', [EmployeeController::class, 'reinstate']],

    ['GET', '/app/payroll', [PayrollController::class, 'index']],
    ['POST', '/app/payroll', [PayrollController::class, 'store']],
    ['GET', '/app/payroll/{id}', [PayrollController::class, 'show']],
    ['GET', '/app/payroll/{id}/lines/{lineId}/payslip', [PayrollController::class, 'payslip']],
    ['POST', '/app/payroll/{id}/approve', [PayrollController::class, 'approve']],
    ['GET', '/me', [WorkspaceController::class, 'me']],
    ['POST', '/me/profile', [AccountController::class, 'updateProfile']],
    ['POST', '/me/password', [AccountController::class, 'changePassword']],
    ['POST', '/me/sign-out-everywhere', [WorkspaceController::class, 'signOutEverywhere']],
    ['POST', '/app/company', [WorkspaceController::class, 'switchCompany']],
];
