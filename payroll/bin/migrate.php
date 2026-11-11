<?php

declare(strict_types=1);

use Mshahara\Payroll\Support\Db;
use Mshahara\Payroll\Support\Env;
use Mshahara\Payroll\Support\Migrator;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Env::load($root . '/.env');
$config = require $root . '/config/app.php';

$server = Db::server($config['db']);
Migrator::createDatabase($server, (string) $config['db']['database']);

$db = Db::connect($config['db']);
$ran = (new Migrator($db, $root . '/migrations'))->run();

if ($ran === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

echo 'Applied: ' . implode(', ', $ran) . "\n";
