<?php

declare(strict_types=1);

/**
 * Dispatches protected filter and moderation administration endpoints from public/index.php.
 * Controllers verify User::isAdmin() before every Filter or Moderation model SQL operation.
 */
function dispatchAdminRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if ($route === "/admin/filters") {
        $controller = new AdminController($pdo);
        $method === "GET" && $controller->filters();
        $method === "POST" && $controller->createFilter();

        adminMethodNotAllowed();
    }

    if ($route === "/admin/moderation") {
        if ($method !== "GET") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $controller->index();
    }

    if ($route === "/admin/moderation/users") {
        if ($method !== "GET") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $controller->users();
    }

    if (preg_match("#^/admin/moderation/cases/(\\d+)/context$#", $route, $matches) === 1) {
        if ($method !== "GET") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $controller->context((int) $matches[1]);
    }

    if (preg_match("#^/admin/moderation/cases/(\\d+)$#", $route, $matches) === 1) {
        if ($method !== "PATCH") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $controller->updateCaseStatus((int) $matches[1]);
    }

    if (preg_match("#^/admin/moderation/(\\d+)$#", $route, $matches) === 1) {
        if ($method !== "PATCH") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $controller->updateReportStatus((int) $matches[1]);
    }

    if (preg_match("#^/admin/filters/(\\d+)$#", $route, $matches) === 1) {
        if ($method !== "PATCH") {
            adminMethodNotAllowed();
        }

        $controller = new AdminController($pdo);
        $controller->moveFilter((int) $matches[1]);
    }

    return false;
}

function adminMethodNotAllowed(): void
{
    Response::json(405, [
        "error" => "Methode non autorisee",
    ]);
}
