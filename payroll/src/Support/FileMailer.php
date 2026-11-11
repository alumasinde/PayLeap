<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

/**
 * Development mailer: writes one .eml per message to storage/mail.
 * Swap for an SMTP/API driver by implementing Mailer; nothing else changes.
 */
final class FileMailer implements Mailer
{
    public function __construct(
        private readonly string $directory,
        private readonly string $from = 'no-reply@localhost',
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create mail directory: ' . $this->directory);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        $file = $this->directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';

        $headers = implode("\r\n", [
            'From: ' . $this->from,
            'To: ' . $to,
            'Subject: ' . $subject,
            'Date: ' . gmdate('r'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
        ]);

        file_put_contents($file, $headers . "\r\n\r\n" . $body . "\r\n");
    }
}
