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
    ) {
    }
}
