<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

use PDO;

final class Db
{
    /** @param array{host:string,port:int,database:string,username:string,password:string} $config */
    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        return self::make($dsn, $config);
    }

    /** @param array{host:string,port:int,database:string,username:string,password:string} $config */
    public static function server(array $config): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']);

        return self::make($dsn, $config);
    }

    /** @param array{username:string,password:string} $config */
    private static function make(string $dsn, array $config): PDO
    {
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Every timestamp in the schema is UTC; keep the session agreeing.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
