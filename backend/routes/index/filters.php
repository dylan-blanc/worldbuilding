<?php

declare(strict_types=1);

function dispatchFilterRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if ($route !== "/filters") {
        return false;
    }

    if ($method !== "GET") {
        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    $controller = new FilterController($pdo);
    $controller->index();

    return true;
}
