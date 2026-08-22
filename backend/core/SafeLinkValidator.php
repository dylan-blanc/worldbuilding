<?php

declare(strict_types=1);

use GuzzleHttp\Client;

/**
 * Provides the single public safe-link validation method used throughout the CMS.
 * Tiptap and publication request REMOTE checks; draft saves request LOCAL checks without network latency.
 */
final class SafeLinkValidator
{
    public function __construct(
        private LinkTargetParser $parser,
        private LinkNavigationPolicy $navigationPolicy,
        private RemoteContentInspector $remoteInspector,
    ) {
    }

    public static function createDefault(): self
    {
        $parser = new LinkTargetParser();
        $navigationPolicy = new LinkNavigationPolicy();
        $networkValidator = new NetworkTargetValidator();
        $signatureDetector = new FileSignatureDetector();
        $client = new Client([
            "allow_redirects" => false,
            "connect_timeout" => 3,
            "timeout" => 6,
            "http_errors" => false,
            "verify" => true,
        ]);

        return new self(
            $parser,
            $navigationPolicy,
            new RemoteContentInspector(
                $client,
                $parser,
                $navigationPolicy,
                $networkValidator,
                $signatureDetector,
            ),
        );
    }

    public function validate(string $url, LinkValidationMode $mode): LinkValidationResult
    {
        try {
            $target = $this->parser->parse($url);

            if ($target->isInternal() || $mode === LinkValidationMode::LOCAL) {
                $this->navigationPolicy->assertAllowed($target);

                return LinkValidationResult::acceptedLocally($target);
            }

            return $this->remoteInspector->inspect($target);
        } catch (Throwable $exception) {
            return LinkValidationResult::rejected(
                $url,
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : "La verification du lien a echoue",
            );
        }
    }
}
