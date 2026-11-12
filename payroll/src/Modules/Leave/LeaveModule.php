<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

final class LeaveModule
{
    public function __construct(
        public readonly LeaveRepository $requests,
        public readonly LeaveService $service,
        public readonly LeaveLedgerRepository $ledger,
        public readonly LeaveBalanceService $balance,
        public readonly LeaveAccrualService $accrual,
        public readonly LeaveSettingsRepository $settings,
        public readonly StatutoryRuleRepository $statutory,
        public readonly LeaveApprovalRepository $approvals,
        public readonly LeavePlannerService $planner,
        public readonly LeaveEncashmentService $encashment,
        public readonly LeavePayrollService $payroll,
        public readonly LeaveReconciliationService $reconciliation,
    ) {
    }
}
