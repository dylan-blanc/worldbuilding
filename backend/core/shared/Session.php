<?php

declare(strict_types=1);

/**
 * Owns native PHP session creation, validation, renewal and destruction for every API request.
 * Authentication flows through frontend -> /api -> Session -> private PHP session files, while
 * controllers only receive the trusted user_id and CSRF token stored on the server.
 */
final class Session
{
    private const NAME = "worldbuilding_session";
    private const IDLE_LIFETIME = 86400;
    private const ABSOLUTE_LIFETIME = 604800;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (self::isProduction() && !self::isHttps()) {
            Response::error("HTTPS requis", 400, "https_required");
        }

        session_name(self::NAME);
        ini_set("session.use_strict_mode", "1");
        ini_set("session.use_cookies", "1");
        ini_set("session.use_only_cookies", "1");
        ini_set("session.use_trans_sid", "0");
        session_set_cookie_params([
            "lifetime" => self::IDLE_LIFETIME,
            ...self::cookieAttributes(),
        ]);

        if (!session_start()) {
            Response::error("Session indisponible", 503, "session_unavailable");
        }
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $now = time();
        $_SESSION = [
            "user_id" => (int) $user["id"],
            "created_at" => $now,
            "last_activity" => $now,
            "csrf_token" => self::generateCsrfToken(),
        ];
        self::writeCookie($now + self::IDLE_LIFETIME);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            setcookie(self::NAME, "", [
                "expires" => time() - 3600,
                ...self::cookieAttributes(),
            ]);
        }

        session_destroy();
    }

    public static function userId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[self::NAME])) {
            return null;
        }

        self::start();

        if (!self::isAuthenticatedSessionValid()) {
            return null;
        }

        $userId = $_SESSION["user_id"] ?? null;

        return is_int($userId) ? $userId : null;
    }

    public static function csrfToken(): string
    {
        self::start();
        $token = $_SESSION["csrf_token"] ?? null;

        if (!is_string($token) || $token === "") {
            $token = self::generateCsrfToken();
            $_SESSION["csrf_token"] = $token;
        }

        return $token;
    }

    public static function hasIdentifierCookie(): bool
    {
        return isset($_COOKIE[self::NAME]) && is_string($_COOKIE[self::NAME]) && $_COOKIE[self::NAME] !== "";
    }

    public static function renewAuthenticated(): bool
    {
        $userId = self::userId();

        if ($userId === null) {
            return false;
        }

        $now = time();
        $absoluteExpiresAt = (int) $_SESSION["created_at"] + self::ABSOLUTE_LIFETIME;
        $expiresAt = min($now + self::IDLE_LIFETIME, $absoluteExpiresAt);
        $_SESSION["last_activity"] = $now;
        self::writeCookie($expiresAt);

        return true;
    }

    public static function renewForMutation(): void
    {
        $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
        in_array($method, ["POST", "PUT", "PATCH", "DELETE"], true) && self::renewAuthenticated();
    }

    private static function isAuthenticatedSessionValid(): bool
    {
        $userId = $_SESSION["user_id"] ?? null;

        if (!is_int($userId)) {
            return false;
        }

        $createdAt = $_SESSION["created_at"] ?? null;
        $lastActivity = $_SESSION["last_activity"] ?? null;
        $now = time();

        if (
            !is_int($createdAt)
            || !is_int($lastActivity)
            || $lastActivity + self::IDLE_LIFETIME <= $now
            || $createdAt + self::ABSOLUTE_LIFETIME <= $now
        ) {
            self::logout();

            return false;
        }

        return true;
    }

    private static function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function writeCookie(int $expiresAt): void
    {
        setcookie(self::NAME, session_id(), [
            "expires" => $expiresAt,
            ...self::cookieAttributes(),
        ]);
    }

    private static function cookieAttributes(): array
    {
        return [
            "path" => "/",
            "secure" => self::isHttps(),
            "httponly" => true,
            "samesite" => "Lax",
        ];
    }

    private static function isProduction(): bool
    {
        return envValue("APP_ENV", "development") === "production";
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER["HTTPS"] ?? "";
        $forwardedProto = strtolower(trim(explode(",", (string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? ""))[0]));

        return $https === "on" || $forwardedProto === "https";
    }
}
