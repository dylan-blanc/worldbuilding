<?php

declare(strict_types=1);

/**
 * Exposes authenticated SafeLinkValidator results to the CMS link form.
 * POST /links/inspect validates the request, then returns MIME, signature and redirect details
 * without persisting or returning the sampled remote bytes to the user.
 */
final class LinkController
{
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

        $result = SafeLinkValidator::createDefault()->validate($url, LinkValidationMode::REMOTE);

        Response::json(200, ["inspection" => $result->toArray()]);
    }
}
