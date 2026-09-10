<?php

declare(strict_types=1);

/**
 * Dispatches the authenticated CMS link inspection endpoint.
 * public/index.php sends POST /links/inspect here before LinkController invokes SafeLinkValidator.
 */
/*
 * Route prise en charge : /links/inspect après suppression du préfixe /api par public/index.php.
 * POST appelle LinkController::inspect(), une autre méthode retourne 405 et une autre route retourne false.
 */
function dispatchLinkRoutes(string $path, string $method): bool
{
    $route = preg_replace("#^/api#", "", $path) ?: "/";

    if ($route !== "/links/inspect") {
        return false;
    }

    if ($method !== "POST") {
        Response::json(405, ["error" => "Methode non autorisee"]);
    }

    (new LinkController())->inspect();

    return true;
}
