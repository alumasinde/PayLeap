<?php

declare(strict_types=1);

use Mshahara\Payroll\Support\SmtpMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

$port = random_int(30000, 40000);
$log = sys_get_temp_dir() . '/mshahara-smtp-' . bin2hex(random_bytes(4)) . '.log';
$process = proc_open(
    [PHP_BINARY, __DIR__ . '/smtp_stub.php', (string) $port, $log],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);

if (!is_resource($process)) {
    echo "FAIL could not start the SMTP stub\n";
    exit(1);
}

usleep(700000);

try {
    (new SmtpMailer('127.0.0.1', $port, '', '', 'none', 'Mshahara <no-reply@example.test>'))
        ->send('person@example.test', 'Welcome', 'Hello from the SMTP test.');
} catch (Throwable $error) {
    echo 'FAIL ' . $error->getMessage() . "\n";
    proc_terminate($process);
    proc_close($process);
    @unlink($log);
    exit(1);
}

usleep(300000);
$transcript = (string) @file_get_contents($log);

$checks = [
    'MAIL FROM uses the bare address' => str_contains($transcript, 'MAIL FROM:<no-reply@example.test>'),
    'RCPT TO uses the recipient' => str_contains($transcript, 'RCPT TO:<person@example.test>'),
    'message is base64 encoded' => str_contains($transcript, 'Content-Transfer-Encoding: base64'),
    'body is in the DATA block' => str_contains($transcript, base64_encode('Hello from the SMTP test.')),
];

$failed = 0;

foreach ($checks as $label => $ok) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

proc_terminate($process);

foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        fclose($pipe);
    }
}

proc_close($process);
@unlink($log);

echo "\n" . (count($checks) - $failed) . '/' . count($checks) . " SMTP checks passed.\n";
exit($failed === 0 ? 0 : 1);
