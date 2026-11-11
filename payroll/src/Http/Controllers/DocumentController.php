<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

/**
 * Company reading material: publishing lives under Settings, while the reader
 * and progress heartbeat are self-service for the employee linked to the
 * signed-in account.
 */
final class DocumentController
{
    public function __construct(private readonly App $app)
    {
    }

    public function store(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->documents->service->publish(
                (int) $company['company_id'],
                $this->app->request->all(),
                $this->app->request->file('file'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/settings?tab=documents');
        }

        $this->app->flash->add('success', 'Document published.');
        $this->app->redirect('/app/settings?tab=documents');
    }

    public function toggle(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $active = $this->app->request->string('active') === '1';

        try {
            $this->app->documents->service->setActive(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                $active,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/settings?tab=documents');
        }

        $this->app->flash->add('success', $active ? 'Document enabled.' : 'Document disabled.');
        $this->app->redirect('/app/settings?tab=documents');
    }

    public function destroy(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->documents->service->remove(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/settings?tab=documents');
        }

        $this->app->flash->add('success', 'Document deleted.');
        $this->app->redirect('/app/settings?tab=documents');
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $employee = $this->linkedEmployee($company);

        echo $this->app->render('me/documents.twig', [
            'employee' => $employee,
            'documents' => $this->app->documents->repository->forEmployee(
                (int) $company['company_id'],
                (int) $employee['id'],
            ),
        ]);
    }

    public function show(array $params): void
    {
        $company = $this->app->requireCompany();
        $employee = $this->linkedEmployee($company);
        $document = $this->availableDocument($company, (int) ($params['id'] ?? 0));

        echo $this->app->render('me/document.twig', [
            'employee' => $employee,
            'document' => $document,
            'read' => $this->app->documents->repository->read(
                (int) $company['company_id'],
                (int) $document['id'],
                (int) $employee['id'],
            ),
        ]);
    }

    public function file(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->linkedEmployee($company);
        $document = $this->availableDocument($company, (int) ($params['id'] ?? 0));
        $path = $this->app->documents->files->absolute((string) $document['file_ref']);

        if ($path === null) {
            $this->app->abort(404, 'That document file is missing.');
        }

        $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) ($document['original_name'] ?? 'document.pdf'));
        $name = $name === '' ? 'document.pdf' : $name;

        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    public function progress(array $params): void
    {
        try {
            $company = $this->app->requireCompany();
            $employee = $this->linkedEmployee($company);
            $result = $this->app->documents->service->recordProgress(
                (int) $company['company_id'],
                (int) $employee['id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->request->input('seconds', 0),
            );
        } catch (HttpException $error) {
            http_response_code($error->status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $error->getMessage()]);

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function linkedEmployee(array $company): array
    {
        $employee = $this->app->employees->register->forUser(
            (int) $company['company_id'],
            (int) $this->app->user['id'],
        );

        if ($employee === null) {
            $this->app->abort(403, 'Your login is not linked to an employee record yet. Ask HR to link it.');
        }

        return $employee;
    }

    /**
     * @param array<string,mixed> $company
     * @return array<string,mixed>
     */
    private function availableDocument(array $company, int $documentId): array
    {
        $document = $this->app->documents->repository->find((int) $company['company_id'], $documentId);

        if ($document === null || (int) $document['active'] !== 1) {
            $this->app->abort(404, 'That document is not available.');
        }

        return $document;
    }
}
