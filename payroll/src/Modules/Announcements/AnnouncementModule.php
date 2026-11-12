<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Modules\Announcements;

final class AnnouncementModule
{
    public function __construct(
        public readonly AnnouncementRepository $repository,
        public readonly AnnouncementService $service,
    ) {
    }
}