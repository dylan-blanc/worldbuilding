<?php

declare(strict_types=1);

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

    public static function error(string $message, int $status = 400): void
    {
        self::json($status, [
            "error" => $message,
        ]);
    }
}
