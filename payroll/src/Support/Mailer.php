<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

interface Mailer
{
    public function send(string $to, string $subject, string $body): void;

    public function sendAttachment(string $to, string $subject, string $body, string $path, string $name): void;
}
