<?php

//ANCHOR - Argon2id methode hash

declare(strict_types=1);

/**
 * Normalizes, validates and hashes passwords used by AuthController and UserProfileController.
 * Plaintext travels only from the request to this class, then an Argon2id hash is persisted by User.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 15;
    public const MAX_LENGTH = 64;

    // coût du hachage Argon2
    // recommendation minimum par OWASP: https://owasp.org/www-project-cheat-sheets/cheatsheets/Password_Storage_Cheat_Sheet.html#argon2
    public const ARGON_OPTIONS = [
        "memory_cost" => 19 * 1024,
        "time_cost" => 2,
        "threads" => 1,
    ];

    public static function normalize(string $password): string
    {
        $normalized = self::normalizeForVerification($password);
        $length = mb_strlen($normalized, "UTF-8");

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            Response::error(
                "Le mot de passe doit contenir entre 15 et 64 caracteres",
                422,
                "invalid_password_length"
            );
        }

        return $normalized;
    }

    public static function normalizeForVerification(string $password): string
    {
        // verifie si les extensions intl et mbstring sont chargées et si la constante PASSWORD_ARGON2ID est définie
        // intl est utilisé pour la normalisation Unicode et mbstring pour la gestion des chaînes multioctets
        // evite un é ayant un encodage différent de é (e + accent) et donc un mot de passe invalide
        // (U+00E9) vs (U+0065 U+0301)
        // mbstring sert a compter correctement les caractères multioctets (comme les emojis) pour la vérification de la longueur du mot de passe
        if (!extension_loaded("intl") || !extension_loaded("mbstring") || !defined("PASSWORD_ARGON2ID")) {
            Response::error("Configuration de securite indisponible", 503, "password_security_unavailable");
        }

        if (!mb_check_encoding($password, "UTF-8")) {
            Response::error("Le mot de passe doit etre une chaine UTF-8 valide", 422, "invalid_password_encoding");
        }

        // assure que é (U+00E9) et é (U+0065 U+0301) sont traités comme le même caractère
        $normalized = Normalizer::normalize($password, Normalizer::FORM_C);

        if (!is_string($normalized)) {
            Response::error("Le mot de passe ne peut pas etre normaliser", 422, "invalid_password_encoding");
        }

        return $normalized;
    }

    public static function hash(string $normalizedPassword): string
    {
        $hash = password_hash($normalizedPassword, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);

        if (!is_string($hash)) {
            throw new RuntimeException("Echec du hachage");
        }

        return $hash;
    }
}
