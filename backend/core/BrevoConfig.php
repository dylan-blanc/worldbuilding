<?php

declare(strict_types=1);

/**
 * Holds the server-only configuration used by the transactional Brevo relay.
 * Docker variables or *_FILE secrets flow through envValue() into this object,
 * then BrevoClientFactory applies them to the single outbound email client.
 */
final class BrevoConfig
{
    public function __construct(
        public readonly string $apiKey,
        public readonly string $senderEmail,
        public readonly string $senderName,
        public readonly float $timeout,
        public readonly int $maxRetries,
    ) {}

    public static function fromEnvironment(): self
    {
        // support docker secret and env variable for dev/prod compatibility
        $apiKey = envValue("BREVO_API_KEY", envValue("BrevoAPIkey", ""));
        $senderEmail = envValue("BREVO_SENDER_EMAIL", "");
        $senderName = envValue("BREVO_SENDER_NAME", "Worldbuilding");
        $timeout = (float) envValue("BREVO_TIMEOUT", "10");
        $maxRetries = (int) envValue("BREVO_MAX_RETRIES", "2");

        $apiKey === "" && throw new RuntimeException("Cle API Brevo manquante");
        filter_var($senderEmail, FILTER_VALIDATE_EMAIL) === false
            && throw new RuntimeException("Adresse expediteur Brevo invalide");
        $senderName === "" && throw new RuntimeException("Nom expediteur Brevo manquant");
        $timeout <= 0 && throw new RuntimeException("Timeout Brevo invalide");
        ($maxRetries < 0 || $maxRetries > 5)
            && throw new RuntimeException("Nombre de tentatives Brevo invalide");

        return new self($apiKey, $senderEmail, $senderName, $timeout, $maxRetries);
    }
}
