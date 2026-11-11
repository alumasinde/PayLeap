<?php

declare(strict_types=1);

namespace Mshahara;

use PDO;
use PDOException;
use RuntimeException;

final class Db
{
    /**
     * @param array<string,string> $config
     */
    public static function connect(array $config): PDO
    {
        $dsn = $config['dsn'] ?? sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }

    /**
     * Connect, or explain why not, without taking the page down.
     *
     * @param array<string,string> $config
     * @return array{0:?PDO,1:?string}
     */
    public static function tryConnect(array $config): array
    {
        try {
            return [self::connect($config), null];
        } catch (PDOException $error) {
            return [null, $error->getMessage()];
        }
    }

    /**
     * Reads php/.env into an array. Values in the real environment win, which
     * keeps `set DB_PASSWORD=... && php -S ...` working.
     *
     * @return array<string,string>
     */
    public static function config(string $envPath): array
    {
        $file = [];
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $file[trim($key)] = trim($value, " \t\"'");
            }
        }

        $get = static function (string $key, string $default) use ($file): string {
            $env = getenv($key);
            if ($env !== false && $env !== '') {
                return $env;
            }

            return $file[$key] ?? $default;
        };

        $config = [
            'host' => $get('DB_HOST', '127.0.0.1'),
            'port' => $get('DB_PORT', '3306'),
            'database' => $get('DB_DATABASE', 'mshahara'),
            'username' => $get('DB_USERNAME', 'root'),
            'password' => $get('DB_PASSWORD', ''),
        ];

        $dsn = $get('DB_DSN', '');
        if ($dsn !== '') {
            $config['dsn'] = $dsn;
        }

        return $config;
    }
}
