<?php

declare(strict_types=1);

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
}
