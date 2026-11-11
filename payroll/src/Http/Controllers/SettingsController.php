<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Http\Controllers;

use Mshahara\Payroll\App;
use Mshahara\Payroll\Modules\Attendance\DeviceRepository;
use Mshahara\Payroll\Support\HttpException;

/**
 * Company policy: identity, leave types, public holidays, shifts and clock or
 * meal devices. Every action behind settings.manage; each save redirects back
 * to the tab it belongs to so a mistake costs one form, not the page.
 */
final class SettingsController
{
    private const TABS = ['organisation', 'leave', 'shifts', 'devices'];

    public function __construct(private readonly App $app)
    {
    }

    public function index(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $companyId = (int) $company['company_id'];

        $tab = (string) $this->app->request->query('tab', 'organisation');
        $tab = $tab === 'holidays' ? 'leave' : $tab;
        $tab = in_array($tab, self::TABS, true) ? $tab : 'organisation';

        $year = (int) $this->app->request->query('year', date('Y'));
        $year = $year >= 2000 && $year <= 2100 ? $year : (int) date('Y');

        echo $this->app->render('app/settings/index.twig', [
            'tab' => $tab,
            'year' => $year,
            'company' => $this->app->companyRepository->find($companyId),
            'leave_types' => $this->app->leave->requests->types($companyId, true),
            'holidays' => $this->app->attendance->holidays->forYear($companyId, $year),
            'shifts' => $this->app->attendance->shifts->all($companyId, true),
            'devices' => $this->app->attendance->devices->all($companyId),
            'genders' => ['any' => 'Everyone', 'female' => 'Female', 'male' => 'Male'],
            'device_kinds' => DeviceRepository::KINDS,
            'device_purposes' => DeviceRepository::PURPOSES,
        ]);
    }

    public function saveCompany(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->companyService->rename(
                (int) $company['company_id'],
                $this->app->request->string('name'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('organisation', $error->getMessage());
        }

        $this->back('organisation', 'Company details saved.', 'success');
    }

    public function saveLeaveType(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->leave->service->saveType(
                (int) $company['company_id'],
                $this->app->request->all(),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('leave', $error->getMessage());
        }

        $this->back('leave', 'Leave type saved.', 'success');
    }

    public function toggleLeaveType(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $active = $this->app->request->string('active') === '1';

        try {
            $this->app->leave->service->setTypeActive(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                $active,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('leave', $error->getMessage());
        }

        $this->back('leave', $active ? 'Leave type enabled.' : 'Leave type disabled.', 'success');
    }

    public function addHoliday(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $date = $this->app->request->string('holiday_date');
        $year = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? (int) substr($date, 0, 4) : (int) date('Y');

        try {
            $this->app->attendance->holidayService->add(
                (int) $company['company_id'],
                $date,
                $this->app->request->string('name'),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('leave', $error->getMessage(), 'error', $year);
        }

        $this->back('leave', 'Public holiday added.', 'success', $year);
    }

    public function removeHoliday(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $year = (int) $this->app->request->string('year', (string) date('Y'));

        try {
            $this->app->attendance->holidayService->remove(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('leave', $error->getMessage(), 'error', $year);
        }

        $this->back('leave', 'Public holiday removed.', 'success', $year);
    }

    public function saveShift(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->attendance->roster->save(
                (int) $company['company_id'],
                $this->app->request->all(),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('shifts', $error->getMessage());
        }

        $this->back('shifts', 'Shift saved.', 'success');
    }

    public function toggleShift(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $active = $this->app->request->string('active') === '1';

        try {
            $this->app->attendance->roster->setActive(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                $active,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('shifts', $error->getMessage());
        }

        $this->back('shifts', $active ? 'Shift enabled.' : 'Shift disabled.', 'success');
    }

    public function saveDevice(): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');

        try {
            $this->app->attendance->deviceService->save(
                (int) $company['company_id'],
                $this->app->request->all(),
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('devices', $error->getMessage());
        }

        $this->back('devices', 'Device saved.', 'success');
    }

    public function toggleDevice(array $params): void
    {
        $company = $this->app->requireCompany();
        $this->app->requirePermission('settings.manage');
        $active = $this->app->request->string('active') === '1';

        try {
            $this->app->attendance->deviceService->setActive(
                (int) $company['company_id'],
                (int) ($params['id'] ?? 0),
                $active,
                (int) $this->app->user['id'],
            );
        } catch (HttpException $error) {
            $this->back('devices', $error->getMessage());
        }

        $this->back('devices', $active ? 'Device enabled.' : 'Device disabled.', 'success');
    }

    private function back(string $tab, string $message, string $type = 'error', ?int $year = null): never
    {
        $this->app->flash->add($type, $message);
        $query = 'tab=' . $tab;

        if ($year !== null) {
            $query .= '&year=' . $year;
        }

        $this->app->redirect('/app/settings?' . $query);
    }
}
