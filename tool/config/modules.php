<?php

declare(strict_types=1);

/**
 * The product modules. Adding one here gives it a public page and a card on
 * the home and pricing pages. Its price share lives in config/pricing.php,
 * and `links` describes which modules it can share records with.
 *
 * status: live | soon | design
 */
return [
    'payroll' => [
        'name' => 'Payroll',
        'status' => 'soon',
        'status_label' => 'Early access',
        'path' => '/payroll',
        'tagline' => 'Gross to net for the whole team, with every statutory line shown.',
        'summary' => 'Run pay for everyone on one screen: NSSF, SHIF, the Housing Levy and PAYE in the order payroll applies them, with a payslip per employee.',
        'cta' => 'See payroll',
        'owners' => ['Finance'],
        'links' => ['employees'],
        'bullets' => [
            [
                'title' => 'Statutory first',
                'body' => 'NSSF on its tiers, SHIF and the levy on gross, reliefs, then PAYE — the same engine as the free calculator.',
            ],
            [
                'title' => 'Rates without a deploy',
                'body' => 'A budget change is a row in the database, not a release. Old payslips keep the rules they were priced on.',
            ],
            [
                'title' => 'Two roles, one run',
                'body' => 'HR works with people, Finance works with cost, and both see the same numbers.',
            ],
        ],
    ],
    'employees' => [
        'name' => 'Employees',
        'status' => 'design',
        'status_label' => 'In design',
        'path' => '/modules/employees',
        'tagline' => 'One record per person: contract, statutory numbers, bank details and documents.',
        'summary' => 'The registry every other module reads from — hire date, contract type, KRA PIN, NSSF and SHIF numbers, bank account, and the documents that go with them.',
        'cta' => 'What is planned',
        'owners' => ['HR'],
        'links' => ['payroll', 'leave-management', 'attendance'],
        'bullets' => [
            [
                'title' => 'A record, once',
                'body' => 'Add a person once; payroll, leave and attendance read the same contract, rate and start date.',
            ],
            [
                'title' => 'Statutory numbers together',
                'body' => 'KRA PIN, NSSF, SHIF and bank details in one place, with a checklist for what is still missing.',
            ],
            [
                'title' => 'Documents that stay attached',
                'body' => 'Contracts, IDs and letters sit with the person, not in a shared folder nobody can audit.',
            ],
        ],
    ],
    'leave-management' => [
        'name' => 'Leave Management',
        'status' => 'design',
        'status_label' => 'In design',
        'path' => '/modules/leave-management',
        'tagline' => 'Annual, sick and maternity leave that lands in the same record as pay.',
        'summary' => 'Requests, balances and approvals in one place, so unpaid leave reaches the payroll run without a spreadsheet changing hands.',
        'cta' => 'What is planned',
        'owners' => ['HR'],
        'links' => ['employees', 'payroll'],
        'bullets' => [
            [
                'title' => 'Balances that keep themselves',
                'body' => 'Annual and sick entitlement per contract, accruing monthly, with carry-over rules you set once.',
            ],
            [
                'title' => 'Approvals without a group chat',
                'body' => 'A request goes to the line manager, then to HR, with the balance visible at every step.',
            ],
            [
                'title' => 'Payroll already knows',
                'body' => 'Approved unpaid leave adjusts the next run, and the payslip explains the difference.',
            ],
        ],
    ],
    'attendance' => [
        'name' => 'Attendance',
        'status' => 'design',
        'status_label' => 'In design',
        'path' => '/modules/attendance',
        'tagline' => 'Days worked, sites and hours — the input payroll and casual pay both need.',
        'summary' => 'A simple register per site or supervisor, so the week is visible while it is happening and payroll does not rebuild it on Friday.',
        'cta' => 'What is planned',
        'owners' => ['HR', 'Finance'],
        'links' => ['employees', 'payroll', 'casual-management'],
        'bullets' => [
            [
                'title' => 'Check-in without hardware',
                'body' => 'A web or phone check-in per site, with a supervisor override for the days the network is down.',
            ],
            [
                'title' => 'Overtime and rest days',
                'body' => 'Rules per contract, so a public holiday or a night shift is priced the same way twice.',
            ],
            [
                'title' => 'Feeds payroll and casuals',
                'body' => 'Approved hours become a run line for monthly staff, or a week of day rates for casuals.',
            ],
        ],
    ],
    'casual-management' => [
        'name' => 'Casual Management',
        'status' => 'design',
        'status_label' => 'In design',
        'path' => '/modules/casual-management',
        'tagline' => 'Day rates, attendance and pay for casual staff who are not on monthly payroll.',
        'summary' => 'Track who worked which days at what rate, then price the week without rebuilding a spreadsheet every Friday.',
        'cta' => 'What is planned',
        'owners' => ['HR', 'Finance'],
        'links' => ['employees', 'attendance', 'payroll'],
        'bullets' => [
            [
                'title' => 'Days worked, not guesses',
                'body' => 'An attendance record per site or supervisor, with the day rate attached to the person.',
            ],
            [
                'title' => 'Weekly cost before Friday',
                'body' => 'Finance sees the week-to-date cost while it is still happening, not after the invoices land.',
            ],
            [
                'title' => 'One export',
                'body' => 'Approved days become a payment file and a payslip, with the statutory treatment shown.',
            ],
        ],
    ],
];
