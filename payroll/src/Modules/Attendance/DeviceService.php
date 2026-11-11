<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

/**
 * Registration of clock and meal terminals. A device is a record of hardware,
 * not a live connection; punches still arrive through AttendanceService.
 */
final class DeviceService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /** @param array<string,mixed> $input */
    public function save(int $companyId, array $input, int $actorId): int
    {
        $deviceId = (int) ($input['id'] ?? 0);

        if ($deviceId > 0 && $this->devices->find($companyId, $deviceId) === null) {
            throw new HttpException(404, 'That device does not exist.');
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 80) {
            throw new HttpException(422, 'Give the device a name of 1 to 80 characters.');
        }

        $kind = (string) ($input['kind'] ?? 'biometric');

        if (!isset(DeviceRepository::KINDS[$kind])) {
            throw new HttpException(422, 'Choose a valid device type.');
        }

        $purpose = (string) ($input['purpose'] ?? 'attendance');

        if (!isset(DeviceRepository::PURPOSES[$purpose])) {
            throw new HttpException(422, 'Choose what the device records.');
        }

        $serial = trim((string) ($input['serial'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));

        if (mb_strlen($serial) > 80) {
            throw new HttpException(422, 'The serial number is too long.');
        }

        if (mb_strlen($location) > 80) {
            throw new HttpException(422, 'The location is too long.');
        }

        if ($this->devices->serialExists($companyId, $serial, $deviceId > 0 ? $deviceId : null)) {
            throw new HttpException(422, 'Another device already uses that serial number.');
        }

        $data = [
            'name' => $name,
            'kind' => $kind,
            'purpose' => $purpose,
            'serial' => $serial === '' ? null : $serial,
            'location' => $location === '' ? null : $location,
            'active' => !empty($input['active']) ? 1 : 0,
        ];

        if ($deviceId > 0) {
            $this->devices->update($companyId, $deviceId, $data);
        } else {
            $deviceId = $this->devices->create($companyId, $data);
        }

        $this->auditLogger->record($companyId, $actorId, 'device.saved', 'attendance_device', (string) $deviceId, [
            'name' => $name,
            'kind' => $kind,
            'purpose' => $purpose,
        ]);

        return $deviceId;
    }

    public function setActive(int $companyId, int $deviceId, bool $active, int $actorId): void
    {
        if ($this->devices->find($companyId, $deviceId) === null) {
            throw new HttpException(404, 'That device does not exist.');
        }

        $this->devices->setActive($companyId, $deviceId, $active);

        $this->auditLogger->record(
            $companyId,
            $actorId,
            'device.' . ($active ? 'enabled' : 'disabled'),
            'attendance_device',
            (string) $deviceId,
        );
    }
}
