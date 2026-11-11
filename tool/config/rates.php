<?php

// The two published rulesets, in the same shape as lib/tax/ke-*.ts.
// Used to seed MySQL (migrations/001_init.sql carries the same data) and as
// the fallback source when the database is not configured.

return [
    [
        'id' => 'ke-2025.02',
        'label' => 'February 2025',
        'effective_from' => '2025-02-01',
        'verified_on' => '2025-11-01',
        'paye' => [
            'bands' => [
                ['up_to' => 24000, 'rate' => 0.10],
                ['up_to' => 32333, 'rate' => 0.25],
                ['up_to' => 500000, 'rate' => 0.30],
                ['up_to' => 800000, 'rate' => 0.325],
                ['up_to' => null, 'rate' => 0.35],
            ],
            'personal_relief_monthly' => 2400,
            'non_cash_benefit_threshold_monthly' => 5000,
        ],
        'nssf' => [
            'employee_rate' => 0.06,
            'employer_rate' => 0.06,
            'tier1_pay_ceiling' => 8000,
            'upper_earnings_limit' => 72000,
        ],
        'shif' => ['employee_rate' => 0.0275, 'minimum_monthly' => 300],
        'housing_levy' => ['employee_rate' => 0.015, 'employer_rate' => 0.015],
        'nita' => ['employer_monthly' => 50],
        'reliefs' => [
            'pension_cap_monthly' => 30000,
            'mortgage_interest_cap_monthly' => 30000,
            'insurance_relief_rate' => 0.15,
            'insurance_relief_cap_monthly' => 5000,
        ],
    ],
    [
        'id' => 'ke-2026.02',
        'label' => 'February 2026',
        'effective_from' => '2026-02-01',
        'verified_on' => '2026-11-11',
        'paye' => [
            'bands' => [
                ['up_to' => 24000, 'rate' => 0.10],
                ['up_to' => 32333, 'rate' => 0.25],
                ['up_to' => 500000, 'rate' => 0.30],
                ['up_to' => 800000, 'rate' => 0.325],
                ['up_to' => null, 'rate' => 0.35],
            ],
            'personal_relief_monthly' => 2400,
            'non_cash_benefit_threshold_monthly' => 5000,
        ],
        'nssf' => [
            'employee_rate' => 0.06,
            'employer_rate' => 0.06,
            'tier1_pay_ceiling' => 9000,
            'upper_earnings_limit' => 108000,
        ],
        'shif' => ['employee_rate' => 0.0275, 'minimum_monthly' => 300],
        'housing_levy' => ['employee_rate' => 0.015, 'employer_rate' => 0.015],
        'nita' => ['employer_monthly' => 50],
        'reliefs' => [
            'pension_cap_monthly' => 30000,
            'mortgage_interest_cap_monthly' => 30000,
            'insurance_relief_rate' => 0.15,
            'insurance_relief_cap_monthly' => 5000,
        ],
    ],
];
