<?php

declare(strict_types=1);

/**
 * Reads JSON, form and multipart request data for every PHP controller.
 * State-changing CMS endpoints also call requireSameOrigin before model SQL or MinIO operations.
 * Production accepts its preferred HTTPS origin and a temporary HTTP fallback while TLS is provisioned.
 */
final class Request
{
    public static function body(): array
    {
        $rawBody = file_get_contents("php://input");
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
}
