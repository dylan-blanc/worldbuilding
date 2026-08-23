<?php

declare(strict_types=1);

final class Session
{
    private const NAME = "worldbuilding_session";

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::NAME);
        session_set_cookie_params([
            "lifetime" => 60 * 60 * 24 * 30, // 60 seconds x 60 minutes x 24 hours x 30 days
            "path" => "/",
            "domain" => "",
            "secure" => self::isHttps(),
            "httponly" => true,
            "samesite" => "Lax",
        ]);
        session_start();
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION["user_id"] = (int) $user["id"];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();

            setcookie(session_name(), "", [
                "expires" => time() - 1,
                "path" => $params["path"],
                "domain" => $params["domain"],
                "secure" => (bool) $params["secure"],
                "httponly" => (bool) $params["httponly"],
                "samesite" => "Lax",
            ]);
        }

        session_destroy();
    }

    public static function userId(): ?int
    {
        self::start();
        $userId = $_SESSION["user_id"] ?? null;

        return is_int($userId) ? $userId : null;
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER["HTTPS"] ?? "";
        $forwardedProto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "";

        return $https === "on" || $forwardedProto === "https";
    }
}
