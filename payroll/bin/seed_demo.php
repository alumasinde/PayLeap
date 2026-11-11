<?php

declare(strict_types=1);

/**
 * Idempotent demo data for the dashboard: departments, a full register, leave
 * requests, a few weeks of attendance and the current month's draft payroll.
 * Run with: php bin/seed_demo.php
 */

use Mshahara\Payroll\App;
use Mshahara\Payroll\Auth\PasswordHasher;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = App::boot($root);
$db = $app->db;
$today = new DateTimeImmutable('today');
$period = $today->format('Y-m');

/* Company ----------------------------------------------------------------- */

$statement = $db->prepare('SELECT id FROM companies WHERE slug = ? LIMIT 1');
$statement->execute(['acme-ltd']);
$companyId = $statement->fetchColumn();

if ($companyId === false) {
    $companyId = $app->companyService->create('Acme Ltd', 'acme-ltd');
    echo "Created company Acme Ltd (#{$companyId})\n";
} else {
    $companyId = (int) $companyId;
    $app->roleRepository->provision($companyId);
    $app->leave->requests->provisionDefaults($companyId);
    echo "Using company Acme Ltd (#{$companyId})\n";
}

/* Demo owner -------------------------------------------------------------- */

$email = 'demo@mshahara.test';
$password = 'DemoPass!2026';
$statement = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$statement->execute([$email]);
$userId = $statement->fetchColumn();
$createdUser = false;

if ($userId === false) {
    $db->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash, email_verified_at, status)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), \'active\')',
    )->execute(['Demo', 'Manager', $email, PasswordHasher::hash($password)]);
    $userId = (int) $db->lastInsertId();
    $createdUser = true;
    echo "Created demo user {$email}\n";
} else {
    $userId = (int) $userId;
}

$db->prepare('INSERT IGNORE INTO memberships (company_id, user_id, status) VALUES (?, ?, \'active\')')
    ->execute([$companyId, $userId]);

$roleId = (int) $db->query(
    'SELECT id FROM roles WHERE company_id = ' . $companyId . ' AND `key` = \'owner\' LIMIT 1',
)->fetchColumn();

$db->prepare('INSERT IGNORE INTO user_roles (user_id, company_id, role_id) VALUES (?, ?, ?)')
    ->execute([$userId, $companyId, $roleId]);

/* Departments ------------------------------------------------------------- */

$departmentNames = [
    'Engineering',
    'Finance',
    'Human Resources',
    'Operations',
    'Sales & Marketing',
    'Customer Support',
];

$departmentIds = [];

foreach ($departmentNames as $name) {
    $statement = $db->prepare('SELECT id FROM departments WHERE company_id = ? AND name = ? LIMIT 1');
    $statement->execute([$companyId, $name]);
    $id = $statement->fetchColumn();

    if ($id === false) {
        $id = $app->employees->service->addDepartment($companyId, $name, $userId);
    }

    $departmentIds[$name] = (int) $id;
}

echo 'Departments ready: ' . count($departmentIds) . "\n";

/* Employees --------------------------------------------------------------- */

$people = [
    ['Brian', 'Kiptoo', 'Engineering', 'Senior Software Engineer', 'permanent', 180000, '2019-04-15'],
    ['Mercy', 'Njeri', 'Engineering', 'Frontend Engineer', 'permanent', 145000, '2021-08-02'],
    ['Kevin', 'Otieno', 'Engineering', 'DevOps Engineer', 'permanent', 165000, '2020-01-20'],
    ['Faith', 'Chepkoech', 'Engineering', 'QA Engineer', 'permanent', 120000, '2022-06-13'],
    ['Samuel', 'Mwangi', 'Engineering', 'Engineering Manager', 'permanent', 260000, '2017-11-06'],
    ['Grace', 'Wanjiku', 'Finance', 'Finance Manager', 'permanent', 240000, '2018-03-12'],
    ['Peter', 'Kamau', 'Finance', 'Accountant', 'permanent', 130000, '2020-09-07'],
    ['Halima', 'Abdi', 'Finance', 'Payroll Officer', 'permanent', 110000, '2021-02-15'],
    ['Dennis', 'Mutua', 'Finance', 'Accounts Assistant', 'permanent', 85000, '2023-05-22'],
    ['Lilian', 'Achieng', 'Human Resources', 'HR Manager', 'permanent', 210000, '2018-07-09'],
    ['Ruth', 'Wambui', 'Human Resources', 'HR Officer', 'permanent', 105000, '2022-10-03'],
    ['Collins', 'Kipkorir', 'Human Resources', 'Recruiter', 'permanent', 95000, '2023-01-16'],
    ['James', 'Mwaura', 'Operations', 'Operations Manager', 'permanent', 230000, '2016-05-30'],
    ['Esther', 'Nyambura', 'Operations', 'Logistics Coordinator', 'permanent', 98000, '2021-12-01'],
    ['Ali', 'Hassan', 'Operations', 'Facilities Officer', 'permanent', 80000, '2022-03-14'],
    ['Naomi', 'Jepkosgei', 'Operations', 'Operations Analyst', 'permanent', 115000, 'NEW8'],
    ['Victor', 'Ochieng', 'Operations', 'Driver', 'permanent', 55000, '2019-09-23'],
    ['Lucy', 'Muthoni', 'Operations', 'Office Administrator', 'permanent', 75000, '2017-02-27'],
    ['Diana', 'Kerubo', 'Sales & Marketing', 'Sales Manager', 'permanent', 200000, '2019-01-14'],
    ['Michael', 'Omondi', 'Sales & Marketing', 'Account Executive', 'contract', 120000, '2024-02-05'],
    ['Cynthia', 'Wairimu', 'Sales & Marketing', 'Marketing Specialist', 'permanent', 125000, '2022-08-08'],
    ['Elvis', 'Barasa', 'Sales & Marketing', 'Sales Representative', 'contract', 90000, '2023-11-20'],
    ['Sharon', 'Atieno', 'Customer Support', 'Support Lead', 'permanent', 140000, '2020-06-01'],
    ['Hassan', 'Mohamed', 'Customer Support', 'Support Agent', 'permanent', 70000, '2021-07-19'],
    ['Irene', 'Nekesa', 'Customer Support', 'Support Agent', 'permanent', 72000, '2022-04-11'],
    ['Anthony', 'Kariuki', 'Customer Support', 'Support Agent', 'intern', 35000, 'NEW3'],
];

$monthStart = $today->format('Y-m-01');
$startDates = [
    'NEW8' => max($monthStart, $today->modify('-8 days')->format('Y-m-d')),
    'NEW3' => max($monthStart, $today->modify('-3 days')->format('Y-m-d')),
];
$banks = [
    ['Equity Bank', 'Westlands'],
    ['KCB Bank', 'Karen'],
    ['Co-operative Bank', 'Upper Hill'],
    ['Absa Bank', 'Ngong Road'],
    ['NCBA Bank', 'Kilimani'],
];

$employeeIds = [];
$femaleNames = [
    'Mercy', 'Faith', 'Grace', 'Halima', 'Lilian', 'Ruth', 'Esther',
    'Naomi', 'Lucy', 'Diana', 'Cynthia', 'Sharon', 'Irene',
];
$genderUpdate = $db->prepare(
    'UPDATE employees SET gender = ?
     WHERE company_id = ? AND employee_no = ? AND (gender IS NULL OR gender = \'\')',
);

foreach ($people as $index => [$first, $last, $department, $title, $type, $salary, $start]) {
    $number = sprintf('ACM-%03d', $index + 1);
    $gender = in_array($first, $femaleNames, true) ? 'female' : 'male';
    $statement = $db->prepare('SELECT id FROM employees WHERE company_id = ? AND employee_no = ? LIMIT 1');
    $statement->execute([$companyId, $number]);
    $existing = $statement->fetchColumn();

    if ($existing !== false) {
        $genderUpdate->execute([$gender, $companyId, $number]);
        $employeeIds[$number] = (int) $existing;
        continue;
    }

    [$bank, $branch] = $banks[$index % count($banks)];
    $startDate = $startDates[$start] ?? $start;

    $id = $app->employees->service->create($companyId, [
        'employee_no' => $number,
        'first_name' => $first,
        'last_name' => $last,
        'gender' => $gender,
        'email' => strtolower($first . '.' . $last) . '@acme.test',
        'phone' => '+2547' . str_pad((string) (10000000 + $index * 137), 8, '0', STR_PAD_LEFT),
        'national_id' => (string) (20000000 + $index * 431),
        'kra_pin' => sprintf('A%09d%s', 100000000 + $index * 97, strtoupper($last[0])),
        'nssf_number' => 'NSSF' . str_pad((string) (1000000 + $index), 7, '0', STR_PAD_LEFT),
        'shif_number' => 'SHIF' . str_pad((string) (2000000 + $index), 7, '0', STR_PAD_LEFT),
        'bank_name' => $bank,
        'bank_branch' => $branch,
        'bank_account' => '01' . str_pad((string) (23456789 + $index * 311), 8, '0', STR_PAD_LEFT),
        'department_id' => $departmentIds[$department],
        'job_title' => $title,
        'employment_type' => $type,
        'monthly_salary' => number_format($salary, 2, '.', ''),
        'start_date' => $startDate,
    ], $userId);

    $employeeIds[$number] = $id;
}

echo 'Employees ready: ' . count($employeeIds) . "\n";

/* Reporting lines --------------------------------------------------------- */

$teamRows = $db->prepare(
    'SELECT id, department_id FROM employees
     WHERE company_id = ? AND status = \'active\'
     ORDER BY id',
);
$teamRows->execute([$companyId]);
$byDepartment = [];

foreach ($teamRows->fetchAll() as $row) {
    if ($row['department_id'] !== null) {
        $byDepartment[(int) $row['department_id']][] = (int) $row['id'];
    }
}

$setManager = $db->prepare(
    'UPDATE employees SET manager_id = ?
     WHERE id = ? AND company_id = ? AND manager_id IS NULL',
);

foreach ($byDepartment as $team) {
    $lead = array_shift($team);

    foreach ($team as $member) {
        $setManager->execute([$lead, $member, $companyId]);
    }
}

echo 'Reporting lines ready: ' . count($byDepartment) . " departments\n";

/* Public holidays --------------------------------------------------------- */

$app->attendance->holidays->provisionDefaults($companyId);
$holidayDate = $today->modify('-6 days');

while ((int) $holidayDate->format('N') >= 6) {
    $holidayDate = $holidayDate->modify('-1 day');
}

$holidayName = 'Acme Founders Day';

if (!$app->attendance->holidays->dateExists($companyId, $holidayDate->format('Y-m-d'))) {
    $app->attendance->holidays->create($companyId, $holidayDate->format('Y-m-d'), $holidayName);
    echo "Public holiday seeded: {$holidayName}\n";
}

/* Leave ------------------------------------------------------------------- */

$leaveCount = (int) $db->query('SELECT COUNT(*) FROM leave_requests WHERE company_id = ' . $companyId)->fetchColumn();

if ($leaveCount === 0) {
    $statement = $db->prepare('SELECT id, name FROM leave_types WHERE company_id = ?');
    $statement->execute([$companyId]);
    $types = [];

    foreach ($statement->fetchAll() as $type) {
        $types[$type['name']] = (int) $type['id'];
    }

    $requests = [
        ['ACM-002', 'Annual leave', $today->modify('-1 day'), $today->modify('+2 days'), 'approved', 'Family time upcountry.'],
        ['ACM-019', 'Annual leave', $today, $today->modify('+4 days'), 'approved', 'Booked leave for a wedding.'],
        ['ACM-005', 'Annual leave', $today->modify('+3 days'), $today->modify('+7 days'), 'pending', 'Annual break with family.'],
        ['ACM-011', 'Sick leave', $today->modify('+1 day'), $today->modify('+2 days'), 'pending', 'Medical review at the clinic.'],
        ['ACM-023', 'Compassionate leave', $today->modify('+7 days'), $today->modify('+9 days'), 'pending', 'Bereavement in the family.'],
        ['ACM-009', 'Annual leave', $today->modify('-20 days'), $today->modify('-18 days'), 'rejected', 'Clashes with month-end close.'],
    ];

    $insert = $db->prepare(
        'INSERT INTO leave_requests
            (company_id, employee_id, leave_type_id, start_date, end_date, days, reason, status,
             decided_by, decided_at, decision_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    );

    foreach ($requests as [$number, $type, $from, $to, $status, $reason]) {
        $days = (int) $from->diff($to)->days + 1;
        $decided = $status === 'pending' ? [null, null, null] : [
            $userId,
            $today->format('Y-m-d H:i:s'),
            $status === 'approved' ? 'Approved.' : 'Not possible this month.',
        ];

        $insert->execute([
            $companyId,
            $employeeIds[$number],
            $types[$type],
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $days,
            $reason,
            $status,
            ...$decided,
        ]);
    }

    echo 'Leave requests seeded: ' . count($requests) . "\n";
} else {
    echo "Leave requests already present ({$leaveCount}), skipped\n";
}

/* Attendance -------------------------------------------------------------- */

$attendanceCount = (int) $db->query('SELECT COUNT(*) FROM attendance_records WHERE company_id = ' . $companyId)->fetchColumn();

if ($attendanceCount === 0) {
    $onLeave = [];

    $statement = $db->prepare(
        'SELECT lr.employee_id, lr.start_date, lr.end_date
         FROM leave_requests lr
         WHERE lr.company_id = ? AND lr.status = \'approved\'',
    );
    $statement->execute([$companyId]);

    foreach ($statement->fetchAll() as $leave) {
        $cursor = new DateTimeImmutable($leave['start_date']);
        $end = new DateTimeImmutable($leave['end_date']);

        while ($cursor <= $end) {
            $onLeave[(int) $leave['employee_id']][$cursor->format('Y-m-d')] = true;
            $cursor = $cursor->modify('+1 day');
        }
    }

    $marked = 0;
    $holidayOn = $holidayDate->format('Y-m-d');
    $cursor = $today->modify('-13 days');

    while ($cursor <= $today) {
        $weekday = (int) $cursor->format('N');
        $date = $cursor->format('Y-m-d');
        $isHoliday = $date === $holidayOn;

        if ($weekday <= 5) {
            $i = 0;

            foreach ($employeeIds as $employeeId) {
                if (isset($onLeave[$employeeId][$date])) {
                    $app->attendance->records->mark($companyId, $employeeId, $date, 'leave', null, null, $userId);
                    $marked++;
                    $i++;
                    continue;
                }

                if ($isHoliday) {
                    if ($i % 4 === 0) {
                        $app->attendance->records->mark($companyId, $employeeId, $date, 'present', '08:00:00', '16:00:00', $userId);
                        $marked++;
                    }

                    $i++;
                    continue;
                }

                if ($date === $today->format('Y-m-d') && $i % 9 === 0) {
                    $i++;
                    continue;
                }

                $seed = $i + (int) $cursor->format('z');

                if ($seed % 19 === 0) {
                    $app->attendance->records->mark($companyId, $employeeId, $date, 'absent', null, null, $userId);
                } elseif ($seed % 7 === 0) {
                    $app->attendance->records->mark($companyId, $employeeId, $date, 'late', '09:12:00', '17:30:00', $userId);
                } else {
                    $app->attendance->records->mark($companyId, $employeeId, $date, 'present', '08:05:00', '17:05:00', $userId);
                }

                $marked++;
                $i++;
            }
        }

        $cursor = $cursor->modify('+1 day');
    }

    echo "Attendance records seeded: {$marked}\n";
} else {
    echo "Attendance already present ({$attendanceCount}), skipped\n";
}

/* Shifts and roster ------------------------------------------------------- */

$app->attendance->shifts->provisionDefaults($companyId);
$rosterCount = (int) $db->query('SELECT COUNT(*) FROM roster_entries WHERE company_id = ' . $companyId)->fetchColumn();

if ($rosterCount === 0) {
    $shiftIds = array_map(
        static fn (array $shift): int => (int) $shift['id'],
        $app->attendance->shifts->all($companyId),
    );

    $monthStart = $today->modify('first day of this month');
    $monthEnd = $monthStart->modify('last day of this month');
    $cursor = $monthStart;
    $assigned = 0;

    while ($cursor <= $monthEnd) {
        if ((int) $cursor->format('N') <= 5) {
            $i = 0;

            foreach ($employeeIds as $employeeId) {
                $app->attendance->shifts->upsert(
                    $companyId,
                    (int) $employeeId,
                    $cursor->format('Y-m-d'),
                    $shiftIds[$i % count($shiftIds)],
                    'shift',
                    $userId,
                );
                $assigned++;
                $i++;
            }
        }

        $cursor = $cursor->modify('+1 day');
    }

    echo "Roster entries seeded: {$assigned}\n";
} else {
    echo "Roster already present ({$rosterCount}), skipped\n";
}

/* Clock and meal devices -------------------------------------------------- */

$demoDevices = [
    ['Reception fingerprint', 'biometric', 'attendance', 'BIO-001', 'Main entrance'],
    ['Gate face scanner', 'face', 'attendance', 'FACE-001', 'Staff gate'],
    ['Canteen meal terminal', 'terminal', 'meal', 'MEAL-001', 'Canteen'],
];
$devicesAdded = 0;

foreach ($demoDevices as [$name, $kind, $purpose, $serial, $location]) {
    if ($app->attendance->devices->serialExists($companyId, $serial)) {
        continue;
    }

    $app->attendance->devices->create($companyId, [
        'name' => $name,
        'kind' => $kind,
        'purpose' => $purpose,
        'serial' => $serial,
        'location' => $location,
        'active' => 1,
    ]);
    $devicesAdded++;
}

echo "Devices ready: {$devicesAdded} added\n";

/* Payroll ----------------------------------------------------------------- */

$statement = $db->prepare('SELECT id FROM payroll_runs WHERE company_id = ? AND period = ? LIMIT 1');
$statement->execute([$companyId, $period]);

if ($statement->fetchColumn() === false) {
    try {
        $runId = $app->payroll->service->prepare(
            $companyId,
            $period,
            $today->format('F Y') . ' payroll',
            $userId,
        );
        echo "Prepared draft payroll run #{$runId} for {$period}\n";
    } catch (Throwable $error) {
        echo 'Payroll skipped: ' . $error->getMessage() . "\n";
    }
} else {
    echo "Payroll run for {$period} already exists, skipped\n";
}

/* Done -------------------------------------------------------------------- */

echo "\nDemo login\n";
echo "  URL:      " . rtrim((string) $app->config['url'], '/') . "/login\n";
echo "  Email:    {$email}\n";
echo "  Password: " . ($createdUser ? $password : '(unchanged from the first seed)') . "\n";
echo "  Company:  Acme Ltd\n";
