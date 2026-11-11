<?php

declare(strict_types=1);

$port = (string) ($argv[1] ?? '0');
$log = (string) ($argv[2] ?? '');

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);

if ($server === false) {
    file_put_contents($log, 'SERVER ERROR ' . $errstr);
    exit(1);
}

$client = @stream_socket_accept($server, 15);

if ($client === false) {
    file_put_contents($log, 'NO CLIENT');
    exit(1);
}

$transcript = '';
$write = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};

$write('220 localhost ESMTP');

while (($line = fgets($client)) !== false) {
    $transcript .= $line;
    $command = strtoupper(trim($line));

    if (str_starts_with($command, 'EHLO')) {
        $write('250-localhost');
        $write('250-AUTH LOGIN');
        $write('250 OK');
    } elseif ($command === 'DATA') {
        $write('354 End data with <CR><LF>.<CR><LF>');

        while (($data = fgets($client)) !== false) {
            $transcript .= $data;

            if (trim($data) === '.') {
                break;
            }
        }

        $write('250 OK');
    } elseif ($command === 'QUIT') {
        $write('221 Bye');
        break;
    } else {
        $write('250 OK');
    }
}

file_put_contents($log, $transcript);
