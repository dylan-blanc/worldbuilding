<?php

declare(strict_types=1);

function dispatchOwnedPageRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if ($route === "/me/owned-pages") {
        if ($method !== "GET") {
            ownedPageMethodNotAllowed();
        }

        $controller = new OwnedPageController($pdo);
        $controller->index();
    }

    if (preg_match("#^/me/owned-pages/(\\d+)/settings$#", $route, $matches) !== 1) {
        return false;
    }

    if ($method !== "POST") {
        ownedPageMethodNotAllowed();
    }

    $controller = new OwnedPageController($pdo);
    $controller->updateSettings((int) $matches[1]);

    return true;
}

function ownedPageMethodNotAllowed(): void
{
    Response::json(405, [
        "error" => "Methode non autorisee",
    ]);
}
