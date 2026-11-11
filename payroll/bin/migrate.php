<?php

declare(strict_types=1);

use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\Migrator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
$config = require $root . '/config/app.php';

$command = $argv[1] ?? 'up';
$steps = (int) ($argv[2] ?? 1);

$server = Db::server($config['db']);
Migrator::createDatabase($server, (string) $config['db']['database']);

$db = Db::connect($config['db']);
$migrator = new Migrator($db, $root . '/migrations');

switch ($command) {
    case 'up':
        $ran = $migrator->run();
        echo $ran === [] ? "Nothing to migrate.\n" : 'Applied: ' . implode(', ', $ran) . "\n";
        break;

    case 'down':
        $ran = $migrator->rollback($steps);
        echo $ran === [] ? "Nothing to roll back.\n" : 'Rolled back: ' . implode(', ', $ran) . "\n";
        break;

    case 'reset':
        $ran = $migrator->reset();
        echo $ran === [] ? "Nothing to roll back.\n" : 'Rolled back: ' . implode(', ', $ran) . "\n";
        break;

    case 'status':
        $status = $migrator->status();

        echo "Applied:\n";
        echo $status['applied'] === [] ? "  (none)\n" : '  ' . implode("\n  ", $status['applied']) . "\n";
        echo "Pending:\n";
        echo $status['pending'] === [] ? "  (none)\n" : '  ' . implode("\n  ", $status['pending']) . "\n";
        break;

    default:
        echo "Usage: php bin/migrate.php [up|down [steps]|reset|status]\n";
        exit(1);
}
