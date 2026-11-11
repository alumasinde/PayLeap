<?php

declare(strict_types=1);

use Mshahara\Paye\Calculator;
use Mshahara\RateSet;

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;

function check(string $label, float $actual, float $expected, float $tolerance = 0.01): void
{
    global $failures;
    $ok = abs($actual - $expected) <= $tolerance;
    if (!$ok) {
        $failures++;
    }
    printf("%s  %s: %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $actual, $ok ? '' : " (expected {$expected})");
}

function checkSame(string $label, mixed $actual, mixed $expected): void
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    printf("%s  %s: %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, var_export($actual, true), $ok ? '' : ' (expected ' . var_export($expected, true) . ')');
}

$rates = RateSet::fromFile(dirname(__DIR__) . '/config/rates.php');
$rules = $rates->byId('ke-2026.02');
$calc = new Calculator();

// KSh 100,000 — the headline example.
$at100k = $calc->payslip(100_000, $rules);
check('100k NSSF Tier I', $at100k['nssf']['tier1'], 540);
check('100k NSSF Tier II', $at100k['nssf']['tier2'], 5_460);
check('100k NSSF total', $at100k['nssf']['total'], 6_000);
check('100k SHIF', $at100k['shif'], 2_750);
check('100k Housing Levy', $at100k['housing_levy'], 1_500);
check('100k taxable pay', $at100k['taxable_pay'], 89_750);
check('100k tax before relief', $at100k['tax_before_relief'], 21_708.35);
check('100k PAYE', $at100k['paye'], 19_308.35);
check('100k net', $at100k['net'], 70_441.65);

// Other salaries.
$at50k = $calc->payslip(50_000, $rules);
check('50k NSSF', $at50k['nssf']['total'], 3_000);
check('50k taxable pay', $at50k['taxable_pay'], 44_875);
check('50k PAYE', $at50k['paye'], 5_845.85);
check('50k net', $at50k['net'], 39_029.15);

$at30k = $calc->payslip(30_000, $rules);
check('30k NSSF', $at30k['nssf']['total'], 1_800);
check('30k SHIF', $at30k['shif'], 825);
check('30k PAYE', $at30k['paye'], 731.25);
check('30k net', $at30k['net'], 26_193.75);

// Low pay: SHIF minimum and personal relief wiping out PAYE.
$at7k = $calc->payslip(7_000, $rules);
check('7k NSSF (Tier I only)', $at7k['nssf']['total'], 420);
check('7k SHIF hits the minimum', $at7k['shif'], 300);
check('7k PAYE is zero', $at7k['paye'], 0);
check('7k net', $at7k['net'], 6_175);

// Band edges.
$big = $calc->payslip(1_000_000, $rules);
$cumulative = static function (float $rate) use ($big): float {
    foreach ($big['bands'] as $band) {
        if (abs($band['rate'] - $rate) < 1e-9) {
            return $band['cumulative_tax'];
        }
    }

    return NAN;
};
check('cumulative tax at KSh 24,000', $cumulative(0.10), 2_400);
check('cumulative tax at KSh 32,333', $cumulative(0.25), 4_483.25);
check('cumulative tax at KSh 500,000', $cumulative(0.30), 144_783.35);
check('cumulative tax at KSh 800,000', $cumulative(0.325), 242_283.35);

// NSSF cap.
check('NSSF capped at KSh 108,000', $calc->payslip(108_000, $rules)['nssf']['total'], 6_480);
check('NSSF capped at KSh 150,000', $calc->payslip(150_000, $rules)['nssf']['total'], 6_480);

// Pension.
$pension = $calc->payslip(100_000, $rules, ['pension' => 10_000]);
check('pension deducted', $pension['pension'], 10_000);
check('pension taxable pay', $pension['taxable_pay'], 79_750);
check('pension PAYE', $pension['paye'], 16_308.35);
check('pension net', $pension['net'], 63_441.65);
check('pension capped at KSh 30,000', $calc->payslip(100_000, $rules, ['pension' => 40_000])['pension'], 30_000);
check('negative pension ignored', $calc->payslip(100_000, $rules, ['pension' => -5_000])['pension'], 0);

// Mortgage.
$mortgage = $calc->payslip(100_000, $rules, ['mortgage_interest' => 30_000]);
check('mortgage allowed', $mortgage['mortgage_interest'], 30_000);
check('mortgage taxable pay', $mortgage['taxable_pay'], 59_750);
check('mortgage net', $mortgage['net'], 49_441.65);

// Insurance relief.
$insurance = $calc->payslip(100_000, $rules, ['insurance_premium' => 10_000]);
check('insurance relief is 15%', $insurance['insurance_relief'], 1_500);
check('insurance PAYE', $insurance['paye'], 17_808.35);
check('insurance net', $insurance['net'], 61_941.65);

// Benefits in kind.
$benefit = $calc->payslip(100_000, $rules, ['benefits_in_kind' => 20_000]);
check('benefit taxed in full above the threshold', $benefit['benefits_in_kind'], 20_000);
check('benefit taxable pay', $benefit['taxable_pay'], 109_750);
check('benefit PAYE', $benefit['paye'], 25_308.35);
check('benefit leaves SHIF alone', $benefit['shif'], 2_750);
check('benefit is not cash', $benefit['net'], 64_441.65);
check('benefit at KSh 5,000 is ignored', $calc->payslip(100_000, $rules, ['benefits_in_kind' => 5_000])['benefits_in_kind'], 0);

// HELB and SACCO, held to what the payslip can carry.
$loans = $calc->payslip(100_000, $rules, ['helb' => 5_000, 'sacco' => 3_000]);
check('loans leave PAYE alone', $loans['paye'], 19_308.35);
check('loans reduce net', $loans['net'], 62_441.65);
$broke = $calc->payslip(10_000, $rules, ['helb' => 20_000]);
check('HELB held to what is left', $broke['helb'], 8_950);
check('no negative net', $broke['net'], 0);

// Everything at once.
$everything = [
    'pension' => 10_000,
    'mortgage_interest' => 5_000,
    'insurance_premium' => 4_000,
    'benefits_in_kind' => 20_000,
    'helb' => 4_000,
    'sacco' => 2_000,
];
$combined = $calc->payslip(100_000, $rules, $everything);
check('combined PAYE', $combined['paye'], 20_208.35);
check('combined net', $combined['net'], 44_541.65);
check('combined total deductions', $combined['total_deductions'], 55_458.35);

// Reverse solve.
$solved = $calc->solveGrossForNet(70_441.65, $rules);
checkSame('reverse 70,441.65 lands on KSh 100,000', $solved['gross'] ?? null, 100_000.0);
$solvedAll = $calc->solveGrossForNet(44_541.65, $rules, $everything);
checkSame('reverse with every deduction lands on KSh 100,000', $solvedAll['gross'] ?? null, 100_000.0);
foreach ([25_000, 50_000, 120_000, 400_000] as $target) {
    $solution = $calc->solveGrossForNet((float) $target, $rules);
    check("reverse {$target} clears the target", (float) $solution['net'] >= $target ? 1 : 0, 1);
    check("reverse {$target} is the smallest gross", $calc->payslip($solution['gross'] - 1, $rules)['net'] < $target ? 1 : 0, 1);
}

// Employer cost.
$cost = $calc->employerCost(100_000, $rules);
check('employer NSSF', $cost['nssf'], 6_000);
check('employer levy', $cost['housing_levy'], 1_500);
check('NITA is KSh 50', $cost['nita'], 50);
check('total cost to company', $cost['total_cost'], 107_550);
check('cost at 30,000', $calc->employerCost(30_000, $rules)['total_cost'], 32_300);
check('no employee, no cost', $calc->employerCost(0, $rules)['total_cost'], 0);

// Invariants.
foreach ([7_000, 30_000, 50_000, 100_000, 108_000, 250_000, 1_000_000] as $gross) {
    $slip = $calc->payslip((float) $gross, $rules, $everything);
    check("gross = deductions + net at {$gross}", $slip['total_deductions'] + $slip['net'], $gross);
}

$previous = -1.0;
for ($gross = 10_000; $gross <= 600_000; $gross += 5_000) {
    $net = $calc->payslip((float) $gross, $rules, $everything)['net'];
    if ($net < $previous) {
        $failures++;
        echo "FAIL  net fell from {$previous} to {$net} at gross {$gross}\n";
    }
    $previous = $net;
}

// Periods.
$periods = $rates->periods();
checkSame('two selectable years', count($periods), 2);
checkSame('2025 starts in February', $periods[0]['months'][0], 2);
checkSame('2025 covers 11 months', count($periods[0]['months']), 11);
checkSame('2026 covers all 12 months', count($periods[1]['months']), 12);
checkSame('January 2026 still uses the 2025 rules', $rates->forPeriod(2026, 1)['id'], 'ke-2025.02');
checkSame('February 2026 switches to the new rules', $rates->forPeriod(2026, 2)['id'], 'ke-2026.02');
checkSame('the default period is when the current rules began', $rates->defaultPeriod(), ['year' => 2026, 'month' => 2]);
check('January 2026 caps NSSF at 4,320', $calc->payslip(108_000, $rates->forPeriod(2026, 1))['nssf']['total'], 4_320);
check('February 2026 caps NSSF at 6,480', $calc->payslip(108_000, $rates->forPeriod(2026, 2))['nssf']['total'], 6_480);
check('January 2026 net at 108,000', $calc->payslip(108_000, $rates->forPeriod(2026, 1))['net'], 76_979.65);
check('February 2026 net at 108,000', $calc->payslip(108_000, $rates->forPeriod(2026, 2))['net'], 75_467.65);
check('2025 NSSF cap is 4,320', $calc->payslip(200_000, $rates->byId('ke-2025.02'))['nssf']['total'], 4_320);

foreach ($periods as $period) {
    foreach ($period['months'] as $month) {
        $slip = $calc->payslip(80_000, $rates->forPeriod($period['year'], $month));
        check(
            "{$period['year']}-{$month} pays out",
            $slip['net'] > 0 && abs(($slip['total_deductions'] + $slip['net']) - 80_000) < 0.01 ? 1 : 0,
            1,
        );
    }
}

echo $failures === 0 ? "\nAll engine checks passed.\n" : "\n{$failures} engine check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
