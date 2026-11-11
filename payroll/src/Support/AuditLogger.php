<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use Mshahara\Payroll\Http\Request;
use PDO;

/**
 * Append-only audit trail. Every state change in the product writes here:
 * who, which company, what, on which record, and from where.
 *
 * The client address and user agent come from the Request, so CLI and queue
 * callers can omit them instead of inventing a fake server entry.
 */
final class AuditLogger
{
    public function __construct(
        private readonly PDO $db,
        private readonly ?Request $request = null,
    ) {
    }

    /** @param array<string,mixed> $meta */
    public function record(
        ?int $companyId,
        ?int $actorId,
        string $action,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $meta = [],
    ): void {
        $ip = $this->request?->ip();
        $userAgent = $this->request?->userAgent();

        $this->db
            ->prepare('INSERT INTO audit_log
                (company_id, actor_user_id, action, subject_type, subject_id, meta, ip, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                $companyId,
                $actorId,
                $action,
                $subjectType,
                $subjectId,
                $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $ip === null || $ip === '' ? null : substr($ip, 0, 45),
                $userAgent === null || $userAgent === '' ? null : substr($userAgent, 0, 255),
            ]);
    }
}
