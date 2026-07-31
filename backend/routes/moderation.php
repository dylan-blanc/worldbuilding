<?php

declare(strict_types=1);

/**
 * Dispatches public-page report creation from backend/public/index.php.
 * POST /api/pages/{id}/reports delegates page/block validation and SQL writes to ModerationController.
 */
function dispatchModerationRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if (preg_match("#^/pages/(\\d+)/reports$#", $route, $matches) !== 1) {
        return false;
    }

    if ($method !== "POST") {
        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    $controller = new ModerationController($pdo);
    $controller->createReport((int) $matches[1]);

    return true;
}
