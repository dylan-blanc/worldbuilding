<?php

declare(strict_types=1);

/**
 * Exposes authenticated SafeLinkValidator results to the CMS link form.
 * POST /links/inspect validates the request, then returns the link decision and redirect path without persisting content.
 */
final class LinkController
{
    /*
     * Endpoint POST /api/links/inspect appelé par useSafeLink.
     * Contrôles d'entrée : même origine, session authentifiée, session renouvelée et URL non vide limitée à 2048 octets.
     * Le verrou de session est fermé avant SafeLinkValidator::validate(REMOTE) pour ne pas bloquer les autres requêtes
     * de la session pendant Guzzle. Sortie JSON : décision, URL contrôlée et message.
     */
    public function inspect(): void
    {
        Request::requireSameOrigin();

        if (Session::userId() === null) {
            Response::error("Non authentifie", 401, "authentication_required");
        }

        Session::renewForMutation();

        $body = Request::body();
        $url = Request::field($body, ["url"]);

        if ($url === "" || strlen($url) > 2048) {
            Response::error("Lien hypertexte invalide", 422, "invalid_link");
        }

        session_status() === PHP_SESSION_ACTIVE && session_write_close();
        $result = SafeLinkValidator::createDefault()->validate($url, LinkValidationMode::REMOTE);

        Response::json(200, ["inspection" => $result->toArray()]);
    }
}
