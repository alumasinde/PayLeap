<?php

declare(strict_types=1);

use Mshahara\Payroll\Http\Controllers\AccessController;
use Mshahara\Payroll\Http\Controllers\AccountController;
use Mshahara\Payroll\Http\Controllers\AnnouncementController;
use Mshahara\Payroll\Http\Controllers\AttendanceController;
use Mshahara\Payroll\Http\Controllers\AuthController;
use Mshahara\Payroll\Http\Controllers\DepartmentController;
use Mshahara\Payroll\Http\Controllers\DocumentController;
use Mshahara\Payroll\Http\Controllers\EmployeeController;
use Mshahara\Payroll\Http\Controllers\EmployeeDepartmentController;
use Mshahara\Payroll\Http\Controllers\FinanceController;
use Mshahara\Payroll\Http\Controllers\InviteController;
use Mshahara\Payroll\Http\Controllers\LeaveController;
use Mshahara\Payroll\Http\Controllers\PasswordController;
use Mshahara\Payroll\Http\Controllers\PayrollController;
use Mshahara\Payroll\Http\Controllers\RoleController;
use Mshahara\Payroll\Http\Controllers\RosterController;
use Mshahara\Payroll\Http\Controllers\SelfServiceController;
use Mshahara\Payroll\Http\Controllers\SelfServicePayslipController;
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
    ['GET', '/hr', [WorkspaceController::class, 'hr']],
    ['GET', '/finance', [WorkspaceController::class, 'finance']],
    ['GET', '/ess', [WorkspaceController::class, 'ess']],
    ['GET', '/app/access', [AccessController::class, 'index']],
    ['POST', '/app/access/invite', [AccessController::class, 'invite']],
    ['POST', '/app/access/members/{id}/role', [AccessController::class, 'changeRole']],
    ['POST', '/app/access/members/{id}/remove', [AccessController::class, 'remove']],
    ['POST', '/app/access/invites/{id}/revoke', [AccessController::class, 'revokeInvite']],
    ['GET', '/app/access/roles/{id}', [RoleController::class, 'edit']],
    ['POST', '/app/access/roles/{id}', [RoleController::class, 'update']],

    ['GET', '/app/departments', [DepartmentController::class, 'index']],
    ['POST', '/app/departments', [DepartmentController::class, 'store']],
    ['GET', '/app/employees', [EmployeeController::class, 'index']],
    ['GET', '/app/employees/new', [EmployeeController::class, 'create']],
    ['POST', '/app/employees', [EmployeeController::class, 'store']],
    ['POST', '/app/employees/cost-center', [EmployeeController::class, 'bulkCostCenter']],
    ['POST', '/app/employees/departments', [EmployeeDepartmentController::class, 'store']],
    ['GET', '/app/employees/{id}', [EmployeeController::class, 'show']],
    ['GET', '/app/employees/{id}/photo', [EmployeeController::class, 'photo']],
    ['GET', '/app/employees/{id}/edit', [EmployeeController::class, 'edit']],
    ['POST', '/app/employees/{id}', [EmployeeController::class, 'update']],
    ['POST', '/app/employees/{id}/archive', [EmployeeController::class, 'archive']],
    ['POST', '/app/employees/{id}/encash-leave', [EmployeeController::class, 'encashLeave']],
    ['POST', '/app/employees/{id}/reinstate', [EmployeeController::class, 'reinstate']],
    ['POST', '/app/employees/{id}/education', [EmployeeController::class, 'addEducation']],
    ['POST', '/app/employees/{id}/education/{educationId}/delete', [EmployeeController::class, 'removeEducation']],

    ['GET', '/app/payroll', [PayrollController::class, 'index']],
    ['POST', '/app/payroll', [PayrollController::class, 'store']],
    ['GET', '/app/payroll/approvals', [PayrollController::class, 'approvals']],
    ['GET', '/app/payroll/{id}/export', [PayrollController::class, 'exportRun']],
    ['POST', '/app/payroll/{id}/adjust', [PayrollController::class, 'adjust']],
    ['GET', '/app/payroll/{id}/lines/{lineId}/payslip', [PayrollController::class, 'payslip']],
    ['GET', '/app/payroll/{id}/lines/{lineId}/payslip.pdf', [PayrollController::class, 'payslipPdf']],
    ['POST', '/app/payroll/{id}/lines/{lineId}/email', [PayrollController::class, 'emailPayslip']],
    ['POST', '/app/payroll/{id}/payslips/email', [PayrollController::class, 'emailPayslips']],
    ['POST', '/app/payroll/{id}/approve', [PayrollController::class, 'approve']],
    ['GET', '/app/payroll/{id}', [PayrollController::class, 'show']],

    ['GET', '/app/finance', [FinanceController::class, 'index']],
    ['GET', '/app/finance/pay-items', [FinanceController::class, 'payItems']],
    ['POST', '/app/finance/pay-items', [FinanceController::class, 'storePayItem']],
    ['POST', '/app/finance/pay-items/{id}/toggle', [FinanceController::class, 'togglePayItem']],
    ['POST', '/app/finance/pay-items/assign', [FinanceController::class, 'assignPayItem']],
    ['POST', '/app/finance/pay-items/assignments/{id}/remove', [FinanceController::class, 'removeAssignment']],
    ['GET', '/app/finance/payments', [FinanceController::class, 'payments']],
    ['POST', '/app/finance/payments', [FinanceController::class, 'createPayment']],
    ['GET', '/app/finance/payments/{id}', [FinanceController::class, 'showPayment']],
    ['POST', '/app/finance/payments/{id}/release', [FinanceController::class, 'releasePayment']],
    ['POST', '/app/finance/payments/{id}/cancel', [FinanceController::class, 'cancelPayment']],
    ['GET', '/app/finance/payments/{id}/file', [FinanceController::class, 'downloadPayment']],
    ['GET', '/app/finance/returns', [FinanceController::class, 'returns']],
    ['POST', '/app/finance/returns', [FinanceController::class, 'generateReturn']],
    ['POST', '/app/finance/returns/{id}/file', [FinanceController::class, 'fileReturn']],
    ['GET', '/app/finance/returns/{id}/download', [FinanceController::class, 'downloadReturn']],
    ['GET', '/app/finance/reports', [FinanceController::class, 'reports']],
    ['GET', '/app/finance/reports/{key}/export', [FinanceController::class, 'exportReport']],
    ['GET', '/app/finance/reports/{key}', [FinanceController::class, 'report']],
    ['GET', '/app/finance/loans', [FinanceController::class, 'loans']],
    ['POST', '/app/finance/loans', [FinanceController::class, 'storeLoan']],
    ['POST', '/app/finance/advances', [FinanceController::class, 'storeAdvance']],
    ['POST', '/app/finance/advances/{id}/decide', [FinanceController::class, 'decideAdvance']],
    ['GET', '/app/finance/claims', [FinanceController::class, 'claims']],
    ['POST', '/app/finance/claims/expense', [FinanceController::class, 'storeExpense']],
    ['POST', '/app/finance/claims/expense/{id}/decide', [FinanceController::class, 'decideExpense']],
    ['POST', '/app/finance/claims/damage', [FinanceController::class, 'storeDamage']],
    ['POST', '/app/finance/claims/damage/{id}/decide', [FinanceController::class, 'decideDamage']],
    ['POST', '/app/finance/claims/commission', [FinanceController::class, 'storeCommission']],
    ['POST', '/app/finance/claims/service-charge', [FinanceController::class, 'storeServiceCharge']],
    ['POST', '/app/finance/claims/final-dues', [FinanceController::class, 'storeFinalDue']],
    ['POST', '/app/finance/claims/final-dues/{id}/decide', [FinanceController::class, 'decideFinalDue']],
    ['GET', '/app/audit', [FinanceController::class, 'audit']],

    ['GET', '/app/attendance', [AttendanceController::class, 'index']],
    ['POST', '/app/attendance', [AttendanceController::class, 'mark']],
    ['POST', '/app/attendance/bulk-present', [AttendanceController::class, 'bulkPresent']],
    ['GET', '/app/roster', [RosterController::class, 'index']],
    ['POST', '/app/roster', [RosterController::class, 'assign']],
    ['GET', '/app/leave', [LeaveController::class, 'index']],
    ['POST', '/app/leave/{id}/decide', [LeaveController::class, 'decide']],
    ['POST', '/app/leave/exceptions/{id}/resolve', [LeaveController::class, 'resolveException']],

    ['GET', '/app/settings', [SettingsController::class, 'index']],
    ['POST', '/app/settings/company', [SettingsController::class, 'saveCompany']],
    ['POST', '/app/settings/leave-types', [SettingsController::class, 'saveLeaveType']],
    ['POST', '/app/settings/leave-types/{id}/toggle', [SettingsController::class, 'toggleLeaveType']],
    ['POST', '/app/settings/leave-policy', [SettingsController::class, 'saveLeavePolicy']],
    ['POST', '/app/settings/holidays', [SettingsController::class, 'addHoliday']],
    ['POST', '/app/settings/holidays/{id}/delete', [SettingsController::class, 'removeHoliday']],
    ['POST', '/app/settings/shifts', [SettingsController::class, 'saveShift']],
    ['POST', '/app/settings/shifts/{id}/toggle', [SettingsController::class, 'toggleShift']],
    ['POST', '/app/settings/devices', [SettingsController::class, 'saveDevice']],
    ['POST', '/app/settings/devices/{id}/toggle', [SettingsController::class, 'toggleDevice']],
    ['POST', '/app/settings/documents', [DocumentController::class, 'store']],
    ['POST', '/app/settings/documents/{id}/toggle', [DocumentController::class, 'toggle']],
    ['POST', '/app/settings/documents/{id}/delete', [DocumentController::class, 'destroy']],

    ['GET', '/app/announcements', [AnnouncementController::class, 'index']],
    ['POST', '/app/announcements', [AnnouncementController::class, 'store']],
    ['POST', '/app/announcements/{id}/delete', [AnnouncementController::class, 'destroy']],

    ['GET', '/me', [SelfServiceController::class, 'profile']],
    ['GET', '/me/account', [WorkspaceController::class, 'me']],
    ['GET', '/me/profile', [SelfServiceController::class, 'profile']],
    ['GET', '/me/attendance', [SelfServiceController::class, 'attendance']],
    ['GET', '/me/timesheet', [SelfServiceController::class, 'timesheet']],
    ['GET', '/me/leave', [SelfServiceController::class, 'leave']],
    ['GET', '/me/leave/planner', [SelfServiceController::class, 'planner']],
    ['POST', '/me/leave/request', [SelfServiceController::class, 'submitLeave']],
    ['POST', '/me/leave/{id}/cancel', [SelfServiceController::class, 'cancelLeave']],
    ['GET', '/ess/leave/approvals', [SelfServiceController::class, 'teamLeave']],
    ['GET', '/ess/payslips', [SelfServicePayslipController::class, 'index']],
    ['GET', '/ess/payslips/{line}', [SelfServicePayslipController::class, 'show']],
    ['GET', '/ess/payslips/{line}/pdf', [SelfServicePayslipController::class, 'pdf']],
    ['POST', '/me/password', [AccountController::class, 'changePassword']],
    ['GET', '/me/documents', [DocumentController::class, 'index']],
    ['GET', '/me/documents/{id}', [DocumentController::class, 'show']],
    ['GET', '/me/documents/{id}/file', [DocumentController::class, 'file']],
    ['POST', '/me/documents/{id}/progress', [DocumentController::class, 'progress']],
    ['POST', '/me/sign-out-everywhere', [WorkspaceController::class, 'signOutEverywhere']],
    ['POST', '/app/company', [WorkspaceController::class, 'switchCompany']],
];
