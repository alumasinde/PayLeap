<?php

declare(strict_types=1);

use Mshahara\Payroll\Http\Controllers\AccessController;
use Mshahara\Payroll\Http\Controllers\AccountController;
use Mshahara\Payroll\Http\Controllers\AttendanceController;
use Mshahara\Payroll\Http\Controllers\AuthController;
use Mshahara\Payroll\Http\Controllers\DocumentController;
use Mshahara\Payroll\Http\Controllers\EmployeeController;
use Mshahara\Payroll\Http\Controllers\EmployeeDepartmentController;
use Mshahara\Payroll\Http\Controllers\InviteController;
use Mshahara\Payroll\Http\Controllers\LeaveController;
use Mshahara\Payroll\Http\Controllers\PasswordController;
use Mshahara\Payroll\Http\Controllers\PayrollController;
use Mshahara\Payroll\Http\Controllers\RoleController;
use Mshahara\Payroll\Http\Controllers\RosterController;
use Mshahara\Payroll\Http\Controllers\SelfServiceController;
use Mshahara\Payroll\Http\Controllers\SettingsController;
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
    ['GET', '/app/employees/{id}/photo', [EmployeeController::class, 'photo']],
    ['GET', '/app/employees/{id}/edit', [EmployeeController::class, 'edit']],
    ['POST', '/app/employees/{id}', [EmployeeController::class, 'update']],
    ['POST', '/app/employees/{id}/archive', [EmployeeController::class, 'archive']],
    ['POST', '/app/employees/{id}/reinstate', [EmployeeController::class, 'reinstate']],
    ['POST', '/app/employees/{id}/education', [EmployeeController::class, 'addEducation']],
    ['POST', '/app/employees/{id}/education/{educationId}/delete', [EmployeeController::class, 'removeEducation']],

    ['GET', '/app/payroll', [PayrollController::class, 'index']],
    ['POST', '/app/payroll', [PayrollController::class, 'store']],
    ['GET', '/app/payroll/{id}', [PayrollController::class, 'show']],
    ['GET', '/app/payroll/{id}/lines/{lineId}/payslip', [PayrollController::class, 'payslip']],
    ['POST', '/app/payroll/{id}/approve', [PayrollController::class, 'approve']],

    ['GET', '/app/attendance', [AttendanceController::class, 'index']],
    ['POST', '/app/attendance', [AttendanceController::class, 'mark']],
    ['GET', '/app/roster', [RosterController::class, 'index']],
    ['POST', '/app/roster', [RosterController::class, 'assign']],
    ['GET', '/app/leave', [LeaveController::class, 'index']],
    ['POST', '/app/leave/{id}/decide', [LeaveController::class, 'decide']],

    ['GET', '/app/settings', [SettingsController::class, 'index']],
    ['POST', '/app/settings/company', [SettingsController::class, 'saveCompany']],
    ['POST', '/app/settings/leave-types', [SettingsController::class, 'saveLeaveType']],
    ['POST', '/app/settings/leave-types/{id}/toggle', [SettingsController::class, 'toggleLeaveType']],
    ['POST', '/app/settings/holidays', [SettingsController::class, 'addHoliday']],
    ['POST', '/app/settings/holidays/{id}/delete', [SettingsController::class, 'removeHoliday']],
    ['POST', '/app/settings/shifts', [SettingsController::class, 'saveShift']],
    ['POST', '/app/settings/shifts/{id}/toggle', [SettingsController::class, 'toggleShift']],
    ['POST', '/app/settings/devices', [SettingsController::class, 'saveDevice']],
    ['POST', '/app/settings/devices/{id}/toggle', [SettingsController::class, 'toggleDevice']],
    ['POST', '/app/settings/documents', [DocumentController::class, 'store']],
    ['POST', '/app/settings/documents/{id}/toggle', [DocumentController::class, 'toggle']],
    ['POST', '/app/settings/documents/{id}/delete', [DocumentController::class, 'destroy']],

    ['GET', '/me', [WorkspaceController::class, 'me']],
    ['GET', '/me/profile', [SelfServiceController::class, 'profile']],
    ['POST', '/me/profile', [AccountController::class, 'updateProfile']],
    ['GET', '/me/attendance', [SelfServiceController::class, 'attendance']],
    ['GET', '/me/timesheet', [SelfServiceController::class, 'timesheet']],
    ['GET', '/me/leave', [SelfServiceController::class, 'leave']],
    ['POST', '/me/leave/request', [SelfServiceController::class, 'submitLeave']],
    ['POST', '/me/password', [AccountController::class, 'changePassword']],
    ['GET', '/me/documents', [DocumentController::class, 'index']],
    ['GET', '/me/documents/{id}', [DocumentController::class, 'show']],
    ['GET', '/me/documents/{id}/file', [DocumentController::class, 'file']],
    ['POST', '/me/documents/{id}/progress', [DocumentController::class, 'progress']],
    ['POST', '/me/sign-out-everywhere', [WorkspaceController::class, 'signOutEverywhere']],
    ['POST', '/app/company', [WorkspaceController::class, 'switchCompany']],
];
