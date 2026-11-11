<?php

declare(strict_types=1);

/**
 * Roles are bundles; code checks permissions, never role names.
 *
 * Field-level permissions (employees.salary.view, employees.bank.view) are
 * separate on purpose: "can view an employee" must not imply "can see pay".
 */
return [
    'roles' => [
        'owner' => ['*'],
        'admin' => [
            'company.manage', 'users.manage', 'users.invite', 'audit.view',
            'employees.view', 'employees.edit', 'employees.salary.view', 'employees.bank.view',
            'payroll.view', 'payroll.run', 'payroll.approve', 'payment.release',
            'leave.view', 'leave.approve.team', 'attendance.view', 'reports.view',
        ],
        'hr' => [
            'users.invite',
            'employees.view', 'employees.edit', 'employees.salary.view',
            'leave.view', 'leave.approve.team', 'attendance.view', 'reports.view',
        ],
        'finance' => [
            'employees.view', 'employees.salary.view', 'employees.bank.view',
            'payroll.view', 'payroll.run', 'payroll.approve', 'payment.release',
            'reports.view', 'audit.view',
        ],
        'hod' => [
            'employees.view', 'leave.view', 'leave.approve.team', 'attendance.view',
        ],
        'employee' => [],
        'auditor' => [
            'employees.view', 'employees.salary.view', 'payroll.view', 'reports.view', 'audit.view',
        ],
    ],

    'labels' => [
        'owner' => 'Owner',
        'admin' => 'Administrator',
        'hr' => 'HR',
        'finance' => 'Finance',
        'hod' => 'Head of department',
        'employee' => 'Employee',
        'auditor' => 'Auditor',
    ],
];
