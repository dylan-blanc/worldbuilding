<?php

declare(strict_types=1);

use Brevo\Brevo;
use GuzzleHttp\Client;

/**
 * Builds the private SDK client used only by BrevoMailer.
 * BrevoConfig provides credentials and network limits, then the client performs
 * the single authorized POST /v3/smtp/email call through the Brevo SDK.
 */
final class BrevoClientFactory
{
    public static function create(BrevoConfig $config): Brevo
    {
        return new Brevo(
            apiKey: $config->apiKey,
            options: [
                "client" => new Client([
                    "connect_timeout" => 3.0,
                    "timeout" => $config->timeout,
                ]),
                "timeout" => $config->timeout,
                "maxRetries" => $config->maxRetries,
            ],
        );
    }
}
