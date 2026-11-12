<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Finance;

/** Finance collaborators, built once per request. */
final class FinanceModule
{
    public function __construct(
        public readonly FinanceRepository $repository,
        public readonly PayItemRepository $payItems,
        public readonly PaymentRepository $payments,
        public readonly ReturnRepository $returns,
        public readonly LoanRepository $loans,
        public readonly ClaimRepository $claims,
        public readonly AuditRepository $audit,
        public readonly FinanceService $service,
        public readonly PayslipDeliveryService $payslips,
    ) {
    }
}
