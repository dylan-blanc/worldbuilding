<?php

declare(strict_types=1);

/**
 * Loads the fixed welcome-email HTML from the backend application files.
 * WelcomeEmailService requests the generic asset here; only validated local markup
 * continues to BrevoMailer and POST /v3/smtp/email during user registration.
 */
final class EmailTemplateProvider
{
    private const MAX_TEMPLATE_BYTES = 262144;

    public function __construct(private readonly string $welcomeTemplatePath)
    {
        trim($welcomeTemplatePath) === ""
            && throw new RuntimeException("Chemin du template email manquant");
    }

    public static function fromApplication(): self
    {
        return new self(__DIR__ . "/../../templates/brevo/emails/welcome.html");
    }

    public function welcomeHtml(): string
    {
        return $this->readHtml($this->welcomeTemplatePath);
    }

    private function readHtml(string $path): string
    {
        (!is_file($path) || !is_readable($path))
            && throw new RuntimeException("Template email indisponible");

        $html = file_get_contents($path);
        $html === false && throw new RuntimeException("Lecture du template email impossible");
        (trim($html) === "" || strlen($html) > self::MAX_TEMPLATE_BYTES)
            && throw new RuntimeException("Taille du template email invalide");

        return $html;
    }
}
