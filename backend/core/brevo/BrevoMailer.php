<?php

declare(strict_types=1);

use Brevo\Brevo;
use Brevo\Exceptions\BrevoApiException;
use Brevo\Exceptions\BrevoException;
use Brevo\TransactionalEmails\Requests\SendTransacEmailRequest;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestSender;
use Brevo\TransactionalEmails\Types\SendTransacEmailRequestToItem;

/**
 * Relays final transactional HTML through POST /v3/smtp/email only.
 * The worker supplies a recipient email plus locally rendered generic HTML;
 * this class never sends profile fields, Brevo templates or CRM attributes.
 */
final class BrevoMailer
{
    public function __construct(
        private readonly Brevo $client,
        private readonly BrevoConfig $config,
    ) {}

    public static function fromEnvironment(): self
    {
        $config = BrevoConfig::fromEnvironment();

        return new self(BrevoClientFactory::create($config), $config);
    }

    public function sendHtml(string $recipientEmail, string $subject, string $htmlContent): string
    {
        filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false
            && throw new InvalidArgumentException("Adresse destinataire invalide");
        trim($subject) === "" && throw new InvalidArgumentException("Sujet email manquant");
        trim($htmlContent) === "" && throw new InvalidArgumentException("Contenu email manquant");

        $request = new SendTransacEmailRequest([
            "sender" => new SendTransacEmailRequestSender([
                "email" => $this->config->senderEmail,
                "name" => $this->config->senderName,
            ]),
            "to" => [
                new SendTransacEmailRequestToItem([
                    "email" => $recipientEmail,
                ]),
            ],
            "subject" => $subject,
            "htmlContent" => $htmlContent,
        ]);

        try {
            $response = $this->client->transactionalEmails->sendTransacEmail($request);
            $messageId = trim((string) ($response?->messageId ?? ""));
            $messageId === "" && throw new RuntimeException("Reponse Brevo invalide");

            return $messageId;
        } catch (BrevoApiException $exception) {
            // Log only the status because external messages may contain request details.
            error_log(sprintf("Brevo API error: status=%d", $exception->getCode()));

            throw new RuntimeException("Echec de la remise de l'email", 0, $exception);
        } catch (BrevoException $exception) {
            // Keep the SDK failure generic to protect recipient and content data.
            error_log("Brevo SDK error");

            throw new RuntimeException("Relais email temporairement indisponible", 0, $exception);
        }
    }
}
