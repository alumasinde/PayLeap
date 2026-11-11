<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Attendance;

use PDO;

/**
 * Clock and meal terminals. Registration is a settings concern; the device
 * feed itself posts through AttendanceService so it gets the same validation,
 * tenancy and audit as a manual entry.
 */
final class DeviceRepository
{
    public const KINDS = [
        'biometric' => 'Biometric reader',
        'face' => 'Face scanner',
        'terminal' => 'Time terminal',
    ];

    public const PURPOSES = [
        'attendance' => 'Clock in / out',
        'meal' => 'Meal clock',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $companyId): array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM attendance_devices WHERE company_id = ? ORDER BY active DESC, name',
        );
        $statement->execute([$companyId]);

        return $statement->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $companyId, int $deviceId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM attendance_devices WHERE id = ? AND company_id = ? LIMIT 1',
        );
        $statement->execute([$deviceId, $companyId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function serialExists(int $companyId, string $serial, ?int $exceptId = null): bool
    {
        if ($serial === '') {
            return false;
        }

        $sql = 'SELECT 1 FROM attendance_devices WHERE company_id = ? AND serial = ?';
        $params = [$companyId, $serial];

        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        $statement = $this->db->prepare($sql . ' LIMIT 1');
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $data */
    public function create(int $companyId, array $data): int
    {
        $this->db
            ->prepare(
                'INSERT INTO attendance_devices
                    (company_id, name, kind, purpose, serial, location, ip_address, port, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            )
            ->execute([
                $companyId,
                $data['name'],
                $data['kind'],
                $data['purpose'],
                $data['serial'],
                $data['location'],
                $data['ip_address'] ?? null,
                $data['port'] ?? null,
                $data['active'],
            ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $companyId, int $deviceId, array $data): void
    {
        $this->db
            ->prepare(
                'UPDATE attendance_devices
                 SET name = ?, kind = ?, purpose = ?, serial = ?, location = ?,
                     ip_address = ?, port = ?, active = ?
                 WHERE id = ? AND company_id = ?',
            )
            ->execute([
                $data['name'],
                $data['kind'],
                $data['purpose'],
                $data['serial'],
                $data['location'],
                $data['ip_address'] ?? null,
                $data['port'] ?? null,
                $data['active'],
                $deviceId,
                $companyId,
            ]);
    }

    public function setActive(int $companyId, int $deviceId, bool $active): void
    {
        $this->db
            ->prepare('UPDATE attendance_devices SET active = ? WHERE id = ? AND company_id = ?')
            ->execute([$active ? 1 : 0, $deviceId, $companyId]);
    }
}
