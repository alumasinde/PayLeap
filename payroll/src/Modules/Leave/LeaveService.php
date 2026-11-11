<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Leave;

use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * The approval decision. A request can move once, and the decision and its
 * author are audited.
 */
final class LeaveService
{
    public function __construct(
        private readonly LeaveRepository $requests,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function decide(int $companyId, int $requestId, string $decision, int $actorId, string $note = ''): void
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new HttpException(422, 'Choose approve or reject.');
        }

        $request = $this->requests->find($companyId, $requestId);

        if ($request === null) {
            throw new HttpException(404, 'That leave request does not exist.');
        }

        if ($request['status'] !== 'pending') {
            throw new HttpException(409, 'That leave request has already been decided.');
        }

        $note = trim($note);

        if (mb_strlen($note) > 255) {
            throw new HttpException(422, 'The decision note is too long.');
        }

        if (!$this->requests->decide($companyId, $requestId, $decision, $actorId, $note === '' ? null : $note)) {
            throw new HttpException(409, 'That leave request has already been decided.');
        }

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'leave.request.' . $decision,
            'leave_request',
            (string) $requestId,
            ['employee_id' => (int) $request['employee_id'], 'days' => (float) $request['days']],
        );
    }
}
