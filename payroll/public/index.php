<?php

declare(strict_types=1);

use Mshahara\Payroll\App;
use Mshahara\Payroll\Http\Request;
use Mshahara\Payroll\Support\HttpException;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$request = Request::capture();

// Let the built-in server hand back real files (CSS) untouched, but never
// anything outside the document root.
if (PHP_SAPI === 'cli-server') {
    $candidate = realpath(__DIR__ . $request->path());

    if ($candidate !== false && str_starts_with($candidate, __DIR__ . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        return false;
    }
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; form-action 'self'; frame-src 'self'; frame-ancestors 'self'; base-uri 'self'");

try {
    $app = App::boot($root, $request);
} catch (Throwable $error) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    error_log('Boot failed: ' . $error->getMessage());
    echo '<!doctype html><meta charset="utf-8"><title>Unavailable</title>'
        . '<div style="font:16px/1.6 system-ui;padding:48px 20px;max-width:32rem;margin:auto">'
        . '<h1 style="font-size:22px">Mshahara Payroll is unavailable</h1>'
        . '<p>The database or configuration is not reachable. Try again shortly.</p></div>';
    exit;
}

$app->resolveContext();

if ($app->user !== [] && (int) ($app->user['must_change_password'] ?? 0) === 1) {
    $allowed = ['/me', '/me/password', '/logout', '/me/sign-out-everywhere'];

    if (!in_array($request->path(), $allowed, true)) {
        $app->flash->add('error', 'Choose a new password to continue.');
        $app->redirect('/me');
    }
}

if ($request->isPost()) {
    $token = $request->input('_token') ?? $request->header('x-csrf-token');

    if (!$app->csrf->check(is_string($token) ? $token : null)) {
        $app->errorPage(419, 'Reload the page and submit it again.');
        exit;
    }
}

$app->loadRoutes($root . '/routes/web.php');

try {
    if (!$app->router->dispatch($request->method(), $request->path())) {
        $app->abort(404, 'That page does not exist.');
    }
} catch (HttpException $error) {
    $app->errorPage($error->status, $error->getMessage());
} catch (Throwable $error) {
    error_log('Request failed: ' . $error);
    $app->errorPage(500, $app->config['debug'] ? $error->getMessage() : 'Try again in a moment.');
}
