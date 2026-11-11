<?php

declare(strict_types=1);

use Mshahara\Payroll\App;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$app = App::boot($root);
$db = $app->db;

$name = $argv[1] ?? 'Acme Ltd';
$email = strtolower($argv[2] ?? 'owner@acme.test');

$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
if ($slug === '') {
    $slug = 'company';
}

$statement = $db->prepare('SELECT id FROM companies WHERE slug = ? LIMIT 1');
$statement->execute([$slug]);
$companyId = $statement->fetchColumn();

if ($companyId === false) {
    $companyId = $app->companies->create($name, $slug);
    echo "Created company: {$name} (#{$companyId})\n";
} else {
    $companyId = (int) $companyId;
    echo "Using company: {$name} (#{$companyId})\n";
}

$result = $app->invitations->create($companyId, $email, 'owner');

echo "Owner invite for {$email}:\n";
echo rtrim((string) $app->config['url'], '/') . '/invite/' . $result['token'] . "\n";
echo "The invite email is in {$root}/storage/mail (file driver).\n";
