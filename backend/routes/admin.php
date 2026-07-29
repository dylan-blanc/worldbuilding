<?php

declare(strict_types=1);

/**
 * Dispatches protected filter administration endpoints from public/index.php.
 * AdminController verifies User::isAdmin() before every Filter model SQL operation.
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

    if (preg_match("#^/admin/filters/(\\d+)$#", $route, $matches) !== 1) {
        return false;
    }

    if ($method !== "PATCH") {
        adminMethodNotAllowed();
    }

    $controller = new AdminController($pdo);
    $controller->moveFilter((int) $matches[1]);

    return true;
}

function adminMethodNotAllowed(): void
{
    Response::json(405, [
        "error" => "Methode non autorisee",
    ]);
}
