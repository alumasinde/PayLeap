<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Companies;

use Mshahara\Payroll\Authz\RoleRepository;
use PDO;
use Throwable;

/**
 * Creating a company also creates the roles it will start from, so there is
 * never a window where a company exists with no way to grant access.
 */
final class CompanyService
{
    public function __construct(
        private readonly PDO $db,
        private readonly CompanyRepository $companyRepository,
        private readonly RoleRepository $roleRepository,
    ) {
    }

    public function create(string $name, string $slug): int
    {
        $this->db->beginTransaction();

        try {
            $companyId = $this->companyRepository->create($name, $slug);
            $this->roleRepository->provision($companyId);
            $this->db->commit();

            return $companyId;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
