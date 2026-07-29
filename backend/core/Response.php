<?php

declare(strict_types=1);

/**
 * Sends terminal JSON or empty HTTP responses for every backend controller.
 * Controllers pass validated response data here after session, model, SQL and storage operations.
 * error() normalizes public API failures, while noContent() supports authorization subrequests.
 */
final class Response
{
    public static function json(int $status, array $payload): void
    {
        http_response_code($status);

        if (!headers_sent()) {
            header("Content-Type: application/json; charset=utf-8");
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function noContent(): void
    {
        http_response_code(204);
        exit;
    }

    public static function error(string $message, int $status = 400, string $code = "request_error", array $details = []): void
    {
        $payload = [
            "error" => $message,
            "code" => $code,
        ];

        if ($details !== []) {
            $payload["details"] = $details;
        }

        self::json($status, $payload);
    }
}
