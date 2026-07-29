<?php

declare(strict_types=1);

/**
 * Dispatches authentication, authorization and authenticated profile routes from public/index.php.
 * Login/register/logout and GET /admin/access use AuthController, while GET/POST /me
 * and GET /me/picture use UserProfileController for SQL profile data and private MinIO images.
 */
function dispatchAuthRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";
    $routes = ["/admin/access", "/login", "/logout", "/me", "/me/picture", "/register"];

    if (!in_array($route, $routes, true)) {
        return false;
    }

    if ($route === "/me") {
        $controller = new UserProfileController($pdo);
        $method === "GET" && $controller->show();
        $method === "POST" && $controller->update();

        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    if ($route === "/me/picture") {
        $method === "GET" && (new UserProfileController($pdo))->picture();

        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    if ($route === "/admin/access") {
        $method === "GET" && (new AuthController($pdo))->adminAccess();

        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    if ($method !== "POST") {
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

    if ($route === "/logout") {
        $controller->logout();
    }

    return true;
}
