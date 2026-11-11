<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Users;

use Mshahara\Payroll\Auth\PasswordHasher;
use Mshahara\Payroll\Support\AuditLogger;
use Mshahara\Payroll\Support\HttpException;

final class AccountService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function updateProfile(int $userId, string $firstName, string $lastName, ?int $companyId = null): void
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        if ($firstName === '' || $lastName === '') {
            throw new HttpException(422, 'Enter your first and last name.');
        }

        if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80) {
            throw new HttpException(422, 'That name is too long.');
        }

        $this->userRepository->updateName($userId, $firstName, $lastName);

        $this->auditLogger->record($companyId, $userId, 'account.profile.updated', 'user', (string) $userId);
    }

    public function changePassword(
        int $userId,
        string $current,
        string $new,
        string $confirmation,
        ?int $companyId = null,
    ): int {
        $hash = $this->userRepository->passwordHash($userId);

        if ($hash === null || !PasswordHasher::verify($current, $hash)) {
            throw new HttpException(422, 'Current password is incorrect.');
        }

        $error = PasswordHasher::error($new, $confirmation);

        if ($error !== null) {
            throw new HttpException(422, $error);
        }

        $epoch = $this->userRepository->changePassword($userId, PasswordHasher::hash($new));

        $this->auditLogger->record($companyId, $userId, 'account.password.changed', 'user', (string) $userId);

        return $epoch;
    }
}
