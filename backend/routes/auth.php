<?php

declare(strict_types=1);

function dispatchAuthRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";
    $routes = ["/login", "/logout", "/me", "/register"];

    if (!in_array($route, $routes, true)) {
        return false;
    }

    if ($route === "/me" && $method !== "GET") {
        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    if ($route !== "/me" && $method !== "POST") {
        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    $controller = new AuthController($pdo);

    if ($route === "/register") {
        $controller->register();
    }

    if ($route === "/login") {
        $controller->login();
    }

    if ($route === "/me") {
        $controller->me();
    }

    if ($route === "/logout") {
        $controller->logout();
    }

    return true;
}
