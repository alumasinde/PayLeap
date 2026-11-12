<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Support\HttpException;

/** HR-authored company announcements shown on the employee dashboard. */
final class AnnouncementController
{
    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        echo $this->app->render('app/announcements/index.twig', [
            'announcements' => $this->app->announcements->repository->all((int) $company['company_id']),
        ]);
    }

    public function store(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->announcements->service->publish(
                (int) $company['company_id'],
                $this->app->request->all(),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/announcements');
        }

        $this->app->flash->add('success', 'Announcement published.');
        $this->app->redirect('/app/announcements');
    }

    public function destroy(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->announcements->service->remove(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->app->flash->add('error', $error->getMessage());
            $this->app->redirect('/app/announcements');
        }

        $this->app->flash->add('success', 'Announcement deleted.');
        $this->app->redirect('/app/announcements');
    }
}