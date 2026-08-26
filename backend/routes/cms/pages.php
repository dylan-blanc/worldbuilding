<?php

declare(strict_types=1);

/**
 * Dispatches page, CMS revision and media HTTP endpoints from public/index.php.
 * State-changing routes call authenticated controllers, then models execute prepared SQL or MinIO SDK requests.
 * Specific draft/publish/media/profile-picture patterns are resolved before the generic page field PATCH route.
 */
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

    if (preg_match("#^/pages/(\\d+)/draft$#", $route, $matches) === 1) {
        $controller = new PageController($pdo);
        $id = (int) $matches[1];

        if ($method === "GET") {
            $controller->showDraft($id);
        }

        if ($method === "PUT") {
            $controller->saveDraft($id);
        }

        pageMethodNotAllowed();
    }

    if (preg_match("#^/pages/(\\d+)/publish$#", $route, $matches) === 1) {
        if ($method !== "POST") {
            pageMethodNotAllowed();
        }

        $controller = new PageController($pdo);
        $controller->publish((int) $matches[1]);
    }

    if (preg_match("#^/pages/(\\d+)/media$#", $route, $matches) === 1) {
        $controller = new PageMediaController($pdo);
        $id = (int) $matches[1];

        if ($method === "GET") {
            $controller->show($id);
        }

        if ($method === "POST") {
            $controller->upload($id);
        }

        pageMethodNotAllowed();
    }

    if (preg_match("#^/pages/(\\d+)/picture$#", $route, $matches) === 1) {
        $controller = new PageMediaController($pdo);
        $id = (int) $matches[1];

        if ($method === "GET") {
            $controller->pagePicture($id);
        }

        if ($method === "POST") {
            $controller->uploadPagePicture($id);
        }

        pageMethodNotAllowed();
    }

    if (preg_match("#^/pages/(\\d+)/metadata$#", $route, $matches) === 1) {
        if ($method !== "PUT") {
            pageMethodNotAllowed();
        }

        Request::requireSameOrigin();
        $controller = new PageController($pdo);
        $controller->updateMetadata((int) $matches[1]);
    }

    if (preg_match("#^/pages/(\\d+)/owner-picture$#", $route, $matches) === 1) {
        if ($method !== "GET") {
            pageMethodNotAllowed();
        }

        $controller = new PageMediaController($pdo);
        $controller->ownerPicture((int) $matches[1]);
    }

    if (preg_match("#^/pages/(\\d+)/(title|description|picture|status)$#", $route, $matches) !== 1) {
        return false;
    }

    if ($method !== "PATCH") {
        pageMethodNotAllowed();
    }

    Request::requireSameOrigin();
    $controller = new PageController($pdo);
    $id = (int) $matches[1];
    $field = $matches[2];

    $handlers = [
        "title" => "updateTitle",
        "description" => "updateDescription",
        "picture" => "updatePicture",
        "status" => "updateStatus",
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
