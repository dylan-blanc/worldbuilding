<?php

declare(strict_types=1);

require_once __DIR__ . "/../models/Filter.php";
require_once __DIR__ . "/../controllers/FilterController.php";

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
