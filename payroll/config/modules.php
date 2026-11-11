<?php

declare(strict_types=1);

/**
 * Product modules. Keys match config/modules.php and config/pricing.php in
 * tool/, so the public catalogue, the quote builder and the product agree.
 *
 * Nothing here is built yet beyond the kernel; the status is honest on purpose.
 */
return [
    'employees' => [
        'name' => 'Employees',
        'status' => 'Next',
        'permission' => 'employees.view',
        'note' => 'People, contracts, statutory numbers, documents.',
    ],
    'payroll' => [
        'name' => 'Payroll',
        'status' => 'Planned',
        'permission' => 'payroll.run',
        'note' => 'Runs, payslips, statutory lines, employer cost.',
    ],
    'leave-management' => [
        'name' => 'Leave',
        'status' => 'Planned',
        'permission' => 'leave.view',
        'note' => 'Types, balances, approvals.',
    ],
    'attendance' => [
        'name' => 'Attendance',
        'status' => 'Planned',
        'permission' => 'attendance.view',
        'note' => 'Days, hours, overtime, sites.',
    ],
    'casual-management' => [
        'name' => 'Casual',
        'status' => 'Planned',
        'permission' => 'attendance.view',
        'note' => 'Day rates, muster, weekly pay.',
    ],
    'loans' => [
        'name' => 'Loans',
        'status' => 'Planned',
        'permission' => 'payroll.view',
        'note' => 'Loans, advances, deduction priority.',
    ],
    'reports' => [
        'name' => 'Reports',
        'status' => 'Planned',
        'permission' => 'reports.view',
        'note' => 'P9, P10, statutory returns, GL.',
    ],
    'self-service' => [
        'name' => 'Self service',
        'status' => 'Planned',
        'permission' => 'employees.view',
        'note' => 'Payslips, leave requests, timesheets.',
    ],
];
