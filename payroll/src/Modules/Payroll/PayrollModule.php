<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Payroll;

/**
 * The payroll module's collaborators, built once per request.
 */
final class PayrollModule
{
    public function __construct(
        public readonly PayrollRepository $runs,
        public readonly RateVersionRepository $rates,
        public readonly PayrollService $service,
    ) {
    }
}
