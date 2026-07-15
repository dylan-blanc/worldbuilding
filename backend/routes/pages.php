<?php

declare(strict_types=1);

function dispatchPageRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if ($route === "/pages") {
        $controller = new PageController($pdo);

        if ($method === "GET") {
            $controller->index();
        }

        if ($method === "POST") {
            $controller->create();
        }

        pageMethodNotAllowed();
    }

    if ($route === "/me/pages") {
        if ($method !== "GET") {
            pageMethodNotAllowed();
        }

        $controller = new PageController($pdo);
        $controller->mine();
    }

    if (preg_match("#^/pages/(\\d+)$#", $route, $matches) === 1) {
        if ($method !== "GET") {
            pageMethodNotAllowed();
        }

        $controller = new PageController($pdo);
        $controller->show((int) $matches[1]);
    }

    if (preg_match("#^/pages/(\\d+)/(title|description|picture|status|content)$#", $route, $matches) !== 1) {
        return false;
    }

    if ($method !== "PATCH") {
        pageMethodNotAllowed();
    }

    $controller = new PageController($pdo);
    $id = (int) $matches[1];
    $field = $matches[2];

    $handlers = [
        "title" => "updateTitle",
        "description" => "updateDescription",
        "picture" => "updatePicture",
        "status" => "updateStatus",
        "content" => "updateContent",
    ];
    $controller->{$handlers[$field]}($id);

    return true;
}

function pageMethodNotAllowed(): void
{
    Response::json(405, [
        "error" => "Methode non autorisee",
    ]);
}
