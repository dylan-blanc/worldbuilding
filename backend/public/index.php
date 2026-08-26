<?php

declare(strict_types=1);

/**
 * Boots the PHP API and dispatches each request to authentication, admin, filter, moderation or page routes.
 * Route controllers complete the request through models, SQL/MinIO operations and terminal JSON responses.
 */
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

if (dispatchAdminRoutes($path, $method, $pdo)) {
    exit;
}

if (dispatchFilterRoutes($path, $method, $pdo)) {
    exit;
}

if (dispatchLinkRoutes($path, $method)) {
    exit;
}

if (dispatchModerationRoutes($path, $method, $pdo)) {
    exit;
}

if (dispatchPageRoutes($path, $method, $pdo)) {
    exit;
}

Response::json(404, [
    "error" => "Not found",
]);
