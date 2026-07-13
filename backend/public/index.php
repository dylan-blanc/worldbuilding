<?php

declare(strict_types=1);

require_once __DIR__ . "/../vendor/autoload.php";

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($path === "/api" || $path === "/api/") {
    Response::json(200, [
        "status" => "ok",
        "service" => "worldbuilding-api",
    ]);
}

if (dispatchAuthRoutes($path, $method, $pdo)) {
    exit;
}

if (dispatchFilterRoutes($path, $method, $pdo)) {
    exit;
}

Response::json(404, [
    "error" => "Not found",
]);
