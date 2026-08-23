<?php

declare(strict_types=1);

/**
 * Coordinates the generic email sent after a successful registration.
 * AuthController passes only the recipient address, EmailTemplateProvider loads the fixed
 * backend HTML asset, and BrevoMailer relays it through POST /v3/smtp/email.
 */
final class WelcomeEmailService
{
    private const SUBJECT = "Bienvenue sur WorldBuilding !";

    public function __construct(
        private readonly EmailTemplateProvider $templates,
        private readonly BrevoMailer $mailer,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            EmailTemplateProvider::fromApplication(),
            BrevoMailer::fromEnvironment(),
        );
    }

    public function send(string $recipientEmail): ?string
    {
        filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
            && throw new InvalidArgumentException("Adresse destinataire invalide");

        // Reserved test domains must never trigger a real external delivery.
        if (str_ends_with(strtolower($recipientEmail), ".test")) {
            return null;
        }

        $html = $this->templates->welcomeHtml();
        str_contains(strtolower($html), strtolower($recipientEmail))
            && throw new RuntimeException("Le template contient une donnee destinataire");

        return $this->mailer->sendHtml($recipientEmail, self::SUBJECT, $html);
    }
}
