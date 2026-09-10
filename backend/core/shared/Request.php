<?php

declare(strict_types=1);

/**
 * Reads JSON, form and multipart request data for every PHP controller.
 * public/index.php calls requireMutationSecurity for every state-changing request before routing.
 * Production accepts its preferred HTTPS origin and a temporary HTTP fallback while TLS is provisioned.
 */
final class Request
{
    public static function body(?int $maxBytes = null): array
    {
        $contentLength = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);

        if ($maxBytes !== null && $contentLength > $maxBytes) {
            Response::error("Corps de requete trop volumineux", 413, "request_body_too_large");
        }

        $stream = fopen("php://input", "rb");

        if ($stream === false) {
            Response::error("Corps de requete illisible", 400, "invalid_request_body");
        }

        $rawBody = stream_get_contents($stream, $maxBytes === null ? -1 : $maxBytes + 1);
        fclose($stream);

        if ($maxBytes !== null && is_string($rawBody) && strlen($rawBody) > $maxBytes) {
            Response::error("Corps de requete trop volumineux", 413, "request_body_too_large");
        }

        $contentType = $_SERVER["CONTENT_TYPE"] ?? "";

        if ($rawBody === false || trim($rawBody) === "" || str_contains($contentType, "application/x-www-form-urlencoded") || str_contains($contentType, "multipart/form-data")) {
            return $_POST;
        }

        $body = json_decode($rawBody, true);

        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            Response::error("JSON invalide", 400);
        }

        return $body;
    }

    public static function field(array $body, array $keys, bool $trim = true): string
    {
        foreach ($keys as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                $value = (string) $body[$key];

                return $trim ? trim($value) : $value;
            }
        }

        return "";
    }

    public static function requireSameOrigin(): void
    {
        if (PHP_SAPI === "cli") {
            return;
        }

        $origin = rtrim((string) ($_SERVER["HTTP_ORIGIN"] ?? ""), "/");
        $allowedOrigins = array_values(array_filter(array_unique([
            rtrim(envValue("FRONTEND_URL", ""), "/"),
            rtrim(envValue("FRONTEND_FALLBACK_URL", ""), "/"),
        ])));

        $originAllowed = array_reduce(
            $allowedOrigins,
            static fn (bool $allowed, string $candidate): bool => $allowed || hash_equals($candidate, $origin),
            false,
        );

        if ($origin === "" || !$originAllowed) {
            Response::error("Origine de la requete refusee", 403, "invalid_request_origin");
        }
    }

    public static function requireMutationSecurity(): void
    {
        self::requireSameOrigin();
        $providedToken = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");

        if ($providedToken === "" || !Session::hasIdentifierCookie()) {
            Response::error("Token CSRF invalide", 403, "invalid_csrf_token");
        }

        if (!hash_equals(Session::csrfToken(), $providedToken)) {
            Response::error("Token CSRF invalide", 403, "invalid_csrf_token");
        }
    }
}
