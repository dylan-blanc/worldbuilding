<?php

declare(strict_types=1);

/**
 * Loads the fixed welcome-email HTML from the backend application files.
 * WelcomeEmailService calls welcomeHtml(), this provider validates and reads the local
 * asset, and only that generic markup continues to BrevoMailer and POST /v3/smtp/email.
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
        return new self(__DIR__ . "/../templates/emails/welcome.html");
    }

    public function welcomeHtml(): string
    {
        (!is_file($this->welcomeTemplatePath) || !is_readable($this->welcomeTemplatePath))
            && throw new RuntimeException("Template email indisponible");

        $html = file_get_contents($this->welcomeTemplatePath);
        $html === false && throw new RuntimeException("Lecture du template email impossible");
        (trim($html) === "" || strlen($html) > self::MAX_TEMPLATE_BYTES)
            && throw new RuntimeException("Taille du template email invalide");

        return $html;
    }
}
