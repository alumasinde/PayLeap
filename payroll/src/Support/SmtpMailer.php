<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use RuntimeException;

/**
 * Small SMTP client for production mail. Supports implicit TLS (port 465),
 * STARTTLS (587) and unauthenticated relays for local testing.
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption,
        private readonly string $from,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        foreach ([$to, $subject, $this->from] as $header) {
            if (preg_match('/[\r\n]/', $header) === 1) {
                throw new RuntimeException('Mail headers cannot contain line breaks.');
            }
        }

        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 10);

        if ($socket === false) {
            throw new RuntimeException('SMTP connection failed: ' . ($errstr !== '' ? $errstr : 'unknown error'));
        }

        stream_set_timeout($socket, 10);
        $hostname = gethostname() ?: 'localhost';

        try {
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO ' . $hostname, 250);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Could not start TLS with the SMTP server.');
                }

                $this->command($socket, 'EHLO ' . $hostname, 250);
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->username), 334);
                $this->command($socket, base64_encode($this->password), 235);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->address($this->from) . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            $headers = implode("\r\n", [
                'From: ' . $this->from,
                'To: ' . $to,
                'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
                'Date: ' . gmdate('r'),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: base64',
            ]);

            $payload = $headers . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            fwrite($socket, rtrim($payload, "\r\n") . "\r\n.\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT', 221);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private function command($socket, string $command, int|array $expected): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, $expected);
    }

    /** @param resource $socket */
    private function expect($socket, int|array $expected): void
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, (array) $expected, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
    }

    private function address(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches) === 1) {
            return $matches[1];
        }

        return trim($from);
    }
}
