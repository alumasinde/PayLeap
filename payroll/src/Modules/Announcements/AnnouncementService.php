<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Announcements;

use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

final class AnnouncementService
{
    public function __construct(
        private readonly AnnouncementRepository $announcements,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function publish(int $companyId, array $input, int $actorId): int
    {
        $title = is_scalar($input['title'] ?? null) ? trim((string) $input['title']) : '';
        $body = is_scalar($input['body'] ?? null) ? trim((string) $input['body']) : '';

        if ($title === '' || mb_strlen($title) > 160) {
            throw new HttpException(422, 'Give the announcement a title of 1 to 160 characters.');
        }

        if ($body === '' || mb_strlen($body) > 5000) {
            throw new HttpException(422, 'Write an announcement of 1 to 5000 characters.');
        }

        $announcementId = $this->announcements->create($companyId, [
            'title' => $title,
            'body' => $body,
            'published_at' => date('Y-m-d H:i:s'),
            'created_by' => $actorId,
        ]);

        $this->auditLogger->record($companyId, $actorId, 'announcement.published', 'company_announcement', (string) $announcementId, [
            'title' => $title,
        ]);

        return $announcementId;
    }

    public function remove(int $companyId, int $announcementId, int $actorId): void
    {
        $announcement = $this->announcements->find($companyId, $announcementId);

        if ($announcement === null) {
            throw new HttpException(404, 'That announcement does not exist.');
        }

        $this->announcements->delete($companyId, $announcementId);
        $this->auditLogger->record($companyId, $actorId, 'announcement.deleted', 'company_announcement', (string) $announcementId, [
            'title' => $announcement['title'],
        ]);
    }
}