<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Documents;

use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\FileStore;
use Mshahara\Payroll\Support\HttpException;

/**
 * Publishing company documents and recording reading time. Progress is kept
 * below 100 percent until the minimum reading time has passed; only then does
 * the status become complete.
 */
final class DocumentService
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly AuditLogger $auditLogger,
        private readonly FileStore $files,
    ) {
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $file */
    public function publish(int $companyId, array $input, ?array $file, int $actorId): int
    {
        $title = is_scalar($input['title'] ?? null) ? trim((string) $input['title']) : '';

        if ($title === '' || mb_strlen($title) > 160) {
            throw new HttpException(422, 'Give the document a title of 1 to 160 characters.');
        }

        $description = is_scalar($input['description'] ?? null) ? trim((string) $input['description']) : '';
        $category = is_scalar($input['category'] ?? null) ? trim((string) $input['category']) : '';

        if (mb_strlen($description) > 500) {
            throw new HttpException(422, 'The description is too long.');
        }

        if (mb_strlen($category) > 60) {
            throw new HttpException(422, 'The category is too long.');
        }

        $minRead = (int) ($input['min_read_seconds'] ?? 60);

        if ($minRead < 5 || $minRead > 3600) {
            throw new HttpException(422, 'Minimum reading time must be between 5 seconds and 1 hour.');
        }

        if ($file === null) {
            throw new HttpException(422, 'Choose a PDF to upload.');
        }

        $stored = $this->files->store(
            $file,
            'documents/' . $companyId,
            ['application/pdf' => 'pdf'],
            self::MAX_BYTES,
        );

        $documentId = $this->documents->create($companyId, [
            'title' => $title,
            'description' => $description === '' ? null : $description,
            'category' => $category === '' ? null : $category,
            'file_ref' => $stored['path'],
            'original_name' => $stored['original_name'],
            'mime_type' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'min_read_seconds' => $minRead,
            'active' => 1,
            'published_at' => date('Y-m-d H:i:s'),
            'created_by' => $actorId,
        ]);

        $this->auditLogger->record($companyId, $actorId, 'document.published', 'company_document', (string) $documentId, [
            'title' => $title,
            'min_read_seconds' => $minRead,
        ]);

        return $documentId;
    }

    public function setActive(int $companyId, int $documentId, bool $active, int $actorId): void
    {
        if ($this->documents->find($companyId, $documentId) === null) {
            throw new HttpException(404, 'That document does not exist.');
        }

        $this->documents->setActive($companyId, $documentId, $active);

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'document.' . ($active ? 'enabled' : 'disabled'),
            'company_document',
            (string) $documentId,
        );
    }

    public function remove(int $companyId, int $documentId, int $actorId): void
    {
        $document = $this->documents->find($companyId, $documentId);

        if ($document === null) {
            throw new HttpException(404, 'That document does not exist.');
        }

        $this->documents->delete($companyId, $documentId);
        $this->files->delete((string) $document['file_ref']);

        $this->auditLogger->record($companyId, $actorId, 'document.deleted', 'company_document', (string) $documentId, [
            'title' => $document['title'],
        ]);
    }

    /**
     * @return array{progress_percent:int,seconds_spent:int,status:string,remaining_seconds:int}
     */
    public function recordProgress(int $companyId, int $employeeId, int $documentId, int $seconds): array
    {
        $document = $this->documents->find($companyId, $documentId);

        if ($document === null || (int) $document['active'] !== 1) {
            throw new HttpException(404, 'That document is not available.');
        }

        $read = $this->documents->read($companyId, $documentId, $employeeId);

        if ($read !== null && $read['status'] === 'completed') {
            return [
                'progress_percent' => 100,
                'seconds_spent' => (int) $read['seconds_spent'],
                'status' => 'completed',
                'remaining_seconds' => 0,
            ];
        }

        $elapsed = max(0, min(120, $seconds));
        $spent = min(86400, (int) ($read['seconds_spent'] ?? 0) + $elapsed);
        $minimum = max(1, (int) $document['min_read_seconds']);
        $progress = $spent >= $minimum ? 100 : min(99, (int) floor($spent * 100 / $minimum));
        $status = $progress === 100 ? 'completed' : 'reading';
        $now = date('Y-m-d H:i:s');

        $this->documents->saveRead($companyId, $documentId, $employeeId, [
            'progress_percent' => $progress,
            'seconds_spent' => $spent,
            'status' => $status,
            'started_at' => $read['started_at'] ?? $now,
            'last_seen_at' => $now,
            'completed_at' => $status === 'completed' ? ($read['completed_at'] ?? $now) : null,
        ]);

        return [
            'progress_percent' => $progress,
            'seconds_spent' => $spent,
            'status' => $status,
            'remaining_seconds' => max(0, $minimum - $spent),
        ];
    }
}
