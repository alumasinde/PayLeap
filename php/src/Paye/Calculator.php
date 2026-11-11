<?php

declare(strict_types=1);

namespace Mshahara\Paye;

/**
 * Gross to net for a resident employee on monthly pay.
 *
 * The order is the point of the tool:
 *   1. NSSF (Tier I, then Tier II)  2. SHIF  3. Housing Levy — on cash pay
 *   4. Pension, then owner-occupied mortgage interest, within their caps
 *   5. Taxable pay = cash gross + taxable benefits, less everything above
 *   6. PAYE from the bands, less personal relief, less insurance relief
 *   7. Insurance premiums, HELB and SACCO come off the net
 *
 * Pure PHP: no HTTP, no views, no database. Both the htmx controller and
 * api/v1 call this, so the two can never disagree.
 */
final class Calculator
{
    private static function r2(float $value): float
    {
        return round($value, 2);
    }

    private static function amount(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, (float) $value);
    }

    /**
     * @param array<string,mixed> $rules
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function payslip(float $gross, array $rules, array $options = []): array
    {
        $personalRelief = (float) $rules['paye']['personal_relief_monthly'];

        $g = (is_finite($gross) && $gross > 0) ? self::r2($gross) : 0.0;
        if ($g === 0.0) {
            return [
                'rate_version' => $rules['id'],
                'gross' => 0.0,
                'nssf' => ['tier1' => 0.0, 'tier2' => 0.0, 'total' => 0.0],
                'shif' => 0.0,
                'housing_levy' => 0.0,
                'pension' => 0.0,
                'mortgage_interest' => 0.0,
                'benefits_in_kind' => 0.0,
                'taxable_pay' => 0.0,
                'bands' => [],
                'tax_before_relief' => 0.0,
                'personal_relief' => $personalRelief,
                'insurance_relief' => 0.0,
                'paye' => 0.0,
                'insurance_premium' => 0.0,
                'helb' => 0.0,
                'sacco' => 0.0,
                'post_tax_deductions' => 0.0,
                'total_deductions' => 0.0,
                'net' => 0.0,
                'take_home_share' => 0.0,
            ];
        }

        $employeeRate = (float) $rules['nssf']['employee_rate'];
        $tier1Ceiling = (float) $rules['nssf']['tier1_pay_ceiling'];
        $upperLimit = (float) $rules['nssf']['upper_earnings_limit'];

        $tier1 = self::r2(min($g, $tier1Ceiling) * $employeeRate);
        $tier2Pay = max(0.0, min($g, $upperLimit) - $tier1Ceiling);
        $tier2 = self::r2($tier2Pay * $employeeRate);
        $nssf = self::r2($tier1 + $tier2);

        $shif = self::r2(max(
            (float) $rules['shif']['minimum_monthly'],
            $g * (float) $rules['shif']['employee_rate'],
        ));
        $housingLevy = self::r2($g * (float) $rules['housing_levy']['employee_rate']);
        $statutory = self::r2($nssf + $shif + $housingLevy);

        // Benefits in kind: nothing at or below the threshold, all of it above.
        $requestedBenefits = self::amount($options['benefits_in_kind'] ?? 0);
        $benefits = $requestedBenefits > (float) $rules['paye']['non_cash_benefit_threshold_monthly']
            ? $requestedBenefits
            : 0.0;

        // Pension and mortgage share the room left above the statutory lines.
        $room = max(0.0, $g - $statutory);
        $pension = self::r2(min(
            self::amount($options['pension'] ?? 0),
            (float) $rules['reliefs']['pension_cap_monthly'],
            $room,
        ));
        $mortgage = self::r2(min(
            self::amount($options['mortgage_interest'] ?? 0),
            (float) $rules['reliefs']['mortgage_interest_cap_monthly'],
            max(0.0, $room - $pension),
        ));

        $taxablePay = self::r2(max(0.0, $g + $benefits - $statutory - $pension - $mortgage));

        $bands = [];
        $lower = 0.0;
        $cumulative = 0.0;
        foreach ($rules['paye']['bands'] as $band) {
            $upper = $band['up_to'] === null ? INF : (float) $band['up_to'];
            $slice = max(0.0, min($taxablePay, $upper) - $lower);
            $lower = $upper;

            if ($slice <= 0.0) {
                $bands[] = [
                    'rate' => (float) $band['rate'],
                    'taxable' => 0.0,
                    'tax' => 0.0,
                    'cumulative_tax' => $cumulative,
                ];
                continue;
            }

            $tax = self::r2($slice * (float) $band['rate']);
            $cumulative = self::r2($cumulative + $tax);
            $bands[] = [
                'rate' => (float) $band['rate'],
                'taxable' => $slice,
                'tax' => $tax,
                'cumulative_tax' => $cumulative,
            ];
        }

        $taxBeforeRelief = $cumulative;
        $payeBeforeCredits = self::r2(max(0.0, $taxBeforeRelief - $personalRelief));

        $requestedPremium = self::amount($options['insurance_premium'] ?? 0);
        $insuranceRelief = self::r2(min(
            $requestedPremium * (float) $rules['reliefs']['insurance_relief_rate'],
            (float) $rules['reliefs']['insurance_relief_cap_monthly'],
            $payeBeforeCredits,
        ));
        $paye = self::r2(max(0.0, $payeBeforeCredits - $insuranceRelief));

        // Post-tax items are paid out of what remains, in payroll order.
        $afterTax = max(0.0, self::r2($g - $statutory - $pension - $mortgage - $paye));
        $insurancePremium = self::r2(min($requestedPremium, $afterTax));
        $helb = self::r2(min(
            self::amount($options['helb'] ?? 0),
            max(0.0, $afterTax - $insurancePremium),
        ));
        $sacco = self::r2(min(
            self::amount($options['sacco'] ?? 0),
            max(0.0, $afterTax - $insurancePremium - $helb),
        ));
        $postTax = self::r2($insurancePremium + $helb + $sacco);

        $net = max(0.0, self::r2($g - $statutory - $pension - $mortgage - $paye - $postTax));

        return [
            'rate_version' => $rules['id'],
            'gross' => $g,
            'nssf' => ['tier1' => $tier1, 'tier2' => $tier2, 'total' => $nssf],
            'shif' => $shif,
            'housing_levy' => $housingLevy,
            'pension' => $pension,
            'mortgage_interest' => $mortgage,
            'benefits_in_kind' => $benefits,
            'taxable_pay' => $taxablePay,
            'bands' => $bands,
            'tax_before_relief' => $taxBeforeRelief,
            'personal_relief' => $personalRelief,
            'insurance_relief' => $insuranceRelief,
            'paye' => $paye,
            'insurance_premium' => $insurancePremium,
            'helb' => $helb,
            'sacco' => $sacco,
            'post_tax_deductions' => $postTax,
            'total_deductions' => self::r2($g - $net),
            'net' => $net,
            'take_home_share' => $g > 0 ? $net / $g : 0.0,
        ];
    }

    /**
     * @param array<string,mixed> $rules
     * @return array<string,mixed>
     */
    public function employerCost(float $gross, array $rules, array $options = []): array
    {
        $employee = $this->payslip($gross, $rules, $options);
        $g = $employee['gross'];

        $rate = (float) $rules['nssf']['employer_rate'];
        $tier1Ceiling = (float) $rules['nssf']['tier1_pay_ceiling'];
        $upperLimit = (float) $rules['nssf']['upper_earnings_limit'];

        $tier1 = self::r2(min($g, $tier1Ceiling) * $rate);
        $tier2 = self::r2(max(0.0, min($g, $upperLimit) - $tier1Ceiling) * $rate);
        $nssf = self::r2($tier1 + $tier2);
        $levy = self::r2($g * (float) $rules['housing_levy']['employer_rate']);
        $nita = $g > 0 ? (float) $rules['nita']['employer_monthly'] : 0.0;

        return [
            'rate_version' => $rules['id'],
            'gross' => $g,
            'nssf' => $nssf,
            'housing_levy' => $levy,
            'nita' => $nita,
            'total_cost' => self::r2($g + $nssf + $levy + $nita),
            'employee' => $employee,
        ];
    }

    /**
     * The smallest gross that lands on at least $targetNet.
     *
     * Net rises with gross at every point, so a binary search over whole
     * shillings is exact.
     *
     * @param array<string,mixed> $rules
     * @return array<string,mixed>|null
     */
    public function solveGrossForNet(float $targetNet, array $rules, array $options = []): ?array
    {
        $target = self::amount($targetNet);
        if ($target === 0.0) {
            return $this->payslip(0.0, $rules, $options);
        }

        $high = max(1000, (int) round($target * 2));
        $guard = 0;
        while ($this->payslip((float) $high, $rules, $options)['net'] < $target) {
            $high *= 2;
            if (++$guard > 40) {
                return null;
            }
        }

        $low = 0;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->payslip((float) $mid, $rules, $options)['net'] >= $target) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $this->payslip((float) $low, $rules, $options);
    }
}
