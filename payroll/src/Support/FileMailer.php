<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use RuntimeException;

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
            throw new RuntimeException('Could not create mail directory: ' . $this->directory);
        }
    }

    public function send(string $to, string $subject, string $body): void
    {
        $this->write($to, $subject, $body, null, null);
    }

    public function sendAttachment(string $to, string $subject, string $body, string $path, string $name): void
    {
        $this->write($to, $subject, $body, $path, $name);
    }

    private function write(string $to, string $subject, string $body, ?string $path, ?string $name): void
    {
        $file = $this->directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $headers = [
            'From: ' . $this->from,
            'To: ' . $to,
            'Subject: ' . $subject,
            'Date: ' . gmdate('r'),
            'MIME-Version: 1.0',
        ];

        if ($path === null || $name === null) {
            $headers[] = 'Content-Type: text/plain; charset=utf-8';
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n";
        } else {
            if (!is_file($path)) {
                throw new RuntimeException('Mail attachment does not exist.');
            }

            $boundary = 'mshahara-' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $attachment = chunk_split(base64_encode((string) file_get_contents($path)), 76, "\r\n");
            $payload = implode("\r\n", $headers) . "\r\n\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
                . $body . "\r\n"
                . '--' . $boundary . "\r\n"
                . 'Content-Type: application/pdf; name="' . addcslashes($name, '"\\') . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . addcslashes($name, '"\\') . "\"\r\n\r\n"
                . $attachment . "\r\n"
                . '--' . $boundary . "--\r\n";
        }

        file_put_contents($file, $payload);
    }
}
