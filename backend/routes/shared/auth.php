<?php

declare(strict_types=1);

/**
 * Dispatches authentication, authorization and authenticated profile routes from public/index.php.
 * Login/register/logout and GET /admin/access use AuthController, GET/POST /me and /me/picture use
 * UserProfileController, and /me/notifications routes expose owner-scoped moderation notification SQL.
 */
function dispatchAuthRoutes(string $path, string $method, PDO $pdo): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if ($route === "/me/notifications" || $route === "/me/notifications/unread-count") {
        if ($method !== "GET") {
            Response::json(405, ["error" => "Methode non autorisee"]);
        }

        $controller = new NotificationController($pdo);
        $route === "/me/notifications" ? $controller->index() : $controller->unreadCount();
    }

    if (preg_match("#^/me/notifications/(\\d+)/read$#", $route, $matches) === 1) {
        if ($method !== "PATCH") {
            Response::json(405, ["error" => "Methode non autorisee"]);
        }

        (new NotificationController($pdo))->markRead((int) $matches[1]);
    }

    $routes = ["/admin/access", "/csrf", "/login", "/logout", "/me", "/me/picture", "/register", "/session/activity"];

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

    if ($route === "/csrf") {
        $method === "GET" && (new AuthController($pdo))->csrf();

        Response::json(405, ["error" => "Methode non autorisee"]);
    }

    if ($method !== "POST") {
        Response::json(405, [
            "error" => "Methode non autorisee",
        ]);
    }

    $controller = new AuthController($pdo);

    if ($route === "/session/activity") {
        $controller->activity();
    }

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
