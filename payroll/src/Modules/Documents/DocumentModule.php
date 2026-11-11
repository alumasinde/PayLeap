<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Documents;

use Mshahara\Payroll\Support\FileStore;

final class DocumentModule
{
    public function __construct(
        public readonly DocumentRepository $repository,
        public readonly DocumentService $service,
        public readonly FileStore $files,
    ) {
    }
}
