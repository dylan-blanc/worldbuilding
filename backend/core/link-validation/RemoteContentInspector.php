<?php

declare(strict_types=1);

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Fetches a bounded sample of an external link after SSRF-safe DNS resolution.
 * SafeLinkValidator delegates REMOTE validation here for HTTP/HTML redirects, headers, MIME and HTML signatures.
 */
final class RemoteContentInspector
{
    private const MAX_REDIRECTS = 3;
    private const MAX_INSPECTION_BYTES = 65536;
    private const ALLOWED_MIME_TYPES = ["text/html", "application/xhtml+xml"];

    public function __construct(
        private ClientInterface $client,
        private LinkTargetParser $parser,
        private LinkNavigationPolicy $navigationPolicy,
        private NetworkTargetResolver $networkValidator,
        private FileSignatureDetector $signatureDetector,
    ) {
    }

    public function inspect(LinkTarget $initialTarget): LinkValidationResult
    {
        $currentTarget = $initialTarget;
        $redirects = [];
        $directDownloadDetected = false;

        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $directDownloadDetected = $directDownloadDetected
                    || $this->navigationPolicy->isDirectDownload($currentTarget);
                $ip = $this->networkValidator->resolvePublicIp($currentTarget);
                $response = $this->request($currentTarget, $ip);
                $status = $response->getStatusCode();

                if ($status >= 300 && $status < 400) {
                    $location = trim($response->getHeaderLine("Location"));
                    $response->getBody()->close();

                    if ($location === "" || $hop === self::MAX_REDIRECTS) {
                        throw new DomainException("La limite de 3 redirections a ete depassee");
                    }

                    $redirectUrl = (string) UriResolver::resolve(
                        new Uri($currentTarget->originalUrl),
                        new Uri($location),
                    );
                    $currentTarget = $this->parser->parse($redirectUrl);

                    if ($currentTarget->isInternal()) {
                        throw new DomainException("Une redirection externe ne peut pas cibler une route interne");
                    }

                    $redirects[] = $redirectUrl;
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    $response->getBody()->close();
                    throw new DomainException("Un ou plusieurs liens sont invalide");
                }

                $declaredMime = strtolower(trim(explode(";", $response->getHeaderLine("Content-Type"))[0]));
                $contentDisposition = strtolower($response->getHeaderLine("Content-Disposition"));
                $refreshHeader = trim($response->getHeaderLine("Refresh"));
                $bytes = $this->readBytes($response->getBody());
                $refreshLocation = $this->refreshLocation($refreshHeader, $declaredMime, $bytes);

                if ($refreshLocation !== null) {
                    if ($hop === self::MAX_REDIRECTS) {
                        throw new DomainException("La limite de 3 redirections a ete depassee");
                    }

                    $redirectUrl = (string) UriResolver::resolve(
                        new Uri($currentTarget->originalUrl),
                        new Uri($refreshLocation),
                    );
                    $currentTarget = $this->parser->parse($redirectUrl);

                    if ($currentTarget->isInternal()) {
                        throw new DomainException("Une redirection externe ne peut pas cibler une route interne");
                    }

                    $redirects[] = $redirectUrl;
                    continue;
                }

                return $this->inspectFinalResponse(
                    $initialTarget,
                    $currentTarget,
                    $declaredMime,
                    $contentDisposition,
                    $bytes,
                    $redirects,
                    $directDownloadDetected,
                );
            }
        } catch (Throwable $exception) {
            return LinkValidationResult::rejected(
                $initialTarget->originalUrl,
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : "La verification du lien externe a echoue",
                $currentTarget->originalUrl,
                redirects: $redirects,
            );
        }

        throw new LogicException("Inspection de lien incomplete");
    }

    private function inspectFinalResponse(
        LinkTarget $initialTarget,
        LinkTarget $currentTarget,
        string $declaredMime,
        string $contentDisposition,
        string $bytes,
        array $redirects,
        bool $directDownloadDetected,
    ): LinkValidationResult {
        $signature = $this->signatureDetector->detect($bytes);
        $forcedDownload = str_contains($contentDisposition, "attachment");
        $allowedMime = in_array($declaredMime, self::ALLOWED_MIME_TYPES, true);

        if ($directDownloadDetected || $forcedDownload || !$allowedMime || !$signature->html || $signature->forbidden) {
            return LinkValidationResult::rejected(
                $initialTarget->originalUrl,
                "Les liens directs vers des fichiers telechargeables sont interdits",
                $currentTarget->originalUrl,
                $declaredMime !== "" ? $declaredMime : null,
                $signature->label,
                $signature->hex,
                $redirects,
            );
        }

        return new LinkValidationResult(
            true,
            $initialTarget->originalUrl,
            $currentTarget->originalUrl,
            $declaredMime,
            $signature->label,
            $signature->hex,
            $redirects,
            "Navigation HTML autorisee",
        );
    }

    private function refreshLocation(string $refreshHeader, string $declaredMime, string $bytes): ?string
    {
        if ($refreshHeader !== "") {
            return $this->parseRefreshValue($refreshHeader);
        }

        if (!in_array($declaredMime, self::ALLOWED_MIME_TYPES, true)) {
            return null;
        }

        $document = new DOMDocument();
        $loaded = @$document->loadHTML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if (!$loaded) {
            return null;
        }

        foreach ($document->getElementsByTagName("meta") as $meta) {
            if (strtolower(trim($meta->getAttribute("http-equiv"))) === "refresh") {
                return $this->parseRefreshValue($meta->getAttribute("content"));
            }
        }

        return null;
    }

    private function parseRefreshValue(string $value): string
    {
        if (preg_match("/^\\s*\\d+(?:\\.\\d+)?\\s*;\\s*(?:url\\s*=\\s*)?(.+)$/is", $value, $matches) !== 1) {
            throw new DomainException("Une redirection automatique invalide a ete detectee");
        }

        $location = trim($matches[1]);
        $quote = $location[0] ?? "";

        if (($quote === "\"" || $quote === "'") && str_ends_with($location, $quote)) {
            $location = trim(substr($location, 1, -1));
        }

        if ($location === "") {
            throw new DomainException("Une redirection automatique invalide a ete detectee");
        }

        return $location;
    }

    private function request(LinkTarget $target, string $ip): ResponseInterface
    {
        if (!defined("CURLOPT_RESOLVE")) {
            throw new RuntimeException("Le transport HTTP securise est indisponible");
        }

        $resolvedIp = str_contains($ip, ":") ? "[" . $ip . "]" : $ip;
        $resolvedHost = str_contains($target->host, ":") ? "[" . $target->host . "]" : $target->host;

        return $this->client->request("GET", $target->originalUrl, [
            "allow_redirects" => false,
            "http_errors" => false,
            "connect_timeout" => 3,
            "timeout" => 6,
            "verify" => true,
            "proxy" => "",
            "headers" => [
                "Accept" => "text/html,application/xhtml+xml",
                "Range" => "bytes=0-" . (self::MAX_INSPECTION_BYTES - 1),
                "User-Agent" => "Worldbuilding-SafeLink/1.0",
            ],
            "stream" => true,
            "curl" => [
                CURLOPT_RESOLVE => [$resolvedHost . ":443:" . $resolvedIp],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ],
        ]);
    }

    private function readBytes(StreamInterface $body): string
    {
        try {
            return $body->read(self::MAX_INSPECTION_BYTES);
        } finally {
            $body->close();
        }
    }
}
