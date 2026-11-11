<?php

declare(strict_types=1);

use Mshahara\Payroll\Support\Env;

return [
    'name' => Env::get('APP_NAME', 'Mshahara Payroll'),
    'env' => Env::get('APP_ENV', 'local'),
    'debug' => Env::get('APP_DEBUG', '0') === '1',
    'url' => rtrim((string) Env::get('APP_URL', 'http://127.0.0.1:8090'), '/'),
    'key' => (string) Env::get('APP_KEY', ''),

    'session' => [
        'driver' => Env::get('SESSION_DRIVER', 'database'),
        'name' => Env::get('SESSION_NAME', 'mshahara_payroll_session'),
        'idle' => ((int) Env::get('SESSION_IDLE_MINUTES', '30')) * 60,
        'absolute' => ((int) Env::get('SESSION_ABSOLUTE_HOURS', '12')) * 3600,
    ],

    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'database' => Env::get('DB_DATABASE', 'mshahara_payroll'),
        'username' => Env::get('DB_USERNAME', ''),
        'password' => Env::get('DB_PASSWORD', ''),
    ],

    'mail' => [
        'driver' => Env::get('MAIL_DRIVER', 'file'),
        'from' => Env::get('MAIL_FROM', 'Mshahara Payroll <no-reply@mshaharacheck.co.ke>'),
        'path' => null, // filled by App
    ],
];
