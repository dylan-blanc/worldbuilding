<?php

declare(strict_types=1);

/**
 * Dispatches protected filter and moderation administration endpoints from public/index.php.
 * Controllers verify User::isAdmin(); POST moderation actions persist decisions, notifications and outbox work.
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

    if (preg_match("#^/admin/moderation/cases/(\\d+)/dismiss$#", $route, $matches) === 1) {
        if ($method !== "POST") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $controller->dismissCase((int) $matches[1]);
    }

    if (preg_match("#^/admin/moderation/(\\d+)/(dismiss|remove)$#", $route, $matches) === 1) {
        if ($method !== "POST") {
            adminMethodNotAllowed();
        }

        $controller = new AdminModerationController($pdo);
        $matches[2] === "dismiss"
            ? $controller->dismissReport((int) $matches[1])
            : $controller->removeReportedContent((int) $matches[1]);
    }

    if (preg_match("#^/admin/filters/(\\d+)$#", $route, $matches) === 1) {
        if (!in_array($method, ["PATCH", "DELETE"], true)) {
            adminMethodNotAllowed();
        }

        $controller = new AdminController($pdo);
        $method === "PATCH"
            ? $controller->moveFilter((int) $matches[1])
            : $controller->deleteFilter((int) $matches[1]);
    }

    return false;
}

function adminMethodNotAllowed(): void
{
    Response::json(405, [
        "error" => "Methode non autorisee",
    ]);
}
