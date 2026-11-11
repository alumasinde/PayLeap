<?php

declare(strict_types=1);

/**
 * Pricing is derived, not typed into templates.
 *
 * - bands: the headcount ladder. `price` is the monthly KSh for the base set
 *   (employee records + payroll). `null` means "quoted".
 * - modules: each module's share of the band price. Payroll is the anchor at
 *   1.0, so payroll alone equals the band price. Employee records are free
 *   whenever payroll is selected. Every other module adds its share, which
 *   lets a module be bought on its own. A module added to config/modules.php
 *   without an entry here is billed at the default share below.
 * - usd_rate: indicative KES -> USD, only for display.
 */
return [
    'currency' => [
        'code' => 'KES',
        'usd_rate' => 0.0091,
        'round_to' => 50,
    ],
    'yearly_discount' => 0.10,

    'bands' => [
        ['name' => 'Free', 'min' => 1, 'max' => 2, 'price' => 0],
        ['name' => 'Starter', 'min' => 3, 'max' => 5, 'price' => 750],
        ['name' => 'Grade 1', 'min' => 6, 'max' => 10, 'price' => 1500],
        ['name' => 'Grade 2', 'min' => 11, 'max' => 25, 'price' => 2500],
        ['name' => 'Grade 3', 'min' => 26, 'max' => 50, 'price' => 3000],
        ['name' => 'Grade 4', 'min' => 51, 'max' => 75, 'price' => 3500],
        ['name' => 'Grade 5', 'min' => 76, 'max' => 100, 'price' => 4000],
        ['name' => 'Grade 6', 'min' => 101, 'max' => 150, 'price' => 5000],
        ['name' => 'Grade 7', 'min' => 151, 'max' => 200, 'price' => 6000],
        ['name' => 'Grade 8', 'min' => 201, 'max' => 250, 'price' => 7000],
        ['name' => 'Grade 9', 'min' => 251, 'max' => 300, 'price' => 8000],
        ['name' => 'Grade 10', 'min' => 301, 'max' => 350, 'price' => 9000],
        ['name' => 'Corporate', 'min' => 351, 'max' => 400, 'price' => 10000],
        ['name' => 'BlueChip', 'min' => 401, 'max' => null, 'price' => null],
    ],

    'default_share' => 0.30,

    'modules' => [
        'payroll' => 1.00,
        'employees' => 0.35,
        'leave-management' => 0.35,
        'attendance' => 0.30,
        'casual-management' => 0.35,
    ],
];
