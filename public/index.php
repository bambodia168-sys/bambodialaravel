<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$root = dirname(__DIR__);

if (! is_file($root.'/storage/app/installed.lock')) {
    if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/installer') {
        header('Location: /installer', true, 302);
        exit;
    }
    require $root.'/installer/entry.php';
    exit;
}

if (file_exists($maintenance = $root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
