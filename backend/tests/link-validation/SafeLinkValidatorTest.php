<?php

declare(strict_types=1);

/**
 * Exercises the shared safe-link facade used by Tiptap, CMS draft saves and publication.
 * Tests cover local policies, byte signatures, SSRF ranges, remote MIME responses and redirect handling.
 */

require_once __DIR__ . "/../../vendor/autoload.php";

function expectSafeLink(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Failed SafeLink assertion: " . $label);
    }
}

function expectSafeLinkDomainException(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (DomainException) {
        return;
    }

    throw new RuntimeException("Expected SafeLink DomainException: " . $label);
}

$linkParser = new LinkTargetParser();
$linkPolicy = new LinkNavigationPolicy();
$signatureDetector = new FileSignatureDetector();
$networkValidator = new NetworkTargetValidator();
$executableSignature = $signatureDetector->detect("MZ" . str_repeat("\0", 32));
$htmlSignature = $signatureDetector->detect("<!doctype html><html></html>");
$unknownSignature = $signatureDetector->detect("{\"not\":\"html\"}");

expectSafeLink(
    $executableSignature->forbidden && $executableSignature->label === "Executable Windows (MZ)",
    "Windows executable signature is rejected",
);
expectSafeLink($htmlSignature->html && !$htmlSignature->forbidden, "HTML signature is accepted");
expectSafeLink(!$unknownSignature->html, "unknown text is not classified as HTML");

$localValidator = SafeLinkValidator::createDefault();
expectSafeLink(
    $localValidator->validate("https://www.php.net/index.php", LinkValidationMode::LOCAL)->safe,
    "server-rendered PHP page is accepted locally",
);
expectSafeLink(
    $localValidator->validate("HTTPS://example.com/page", LinkValidationMode::LOCAL)->safe,
    "HTTPS schemes are parsed case-insensitively",
);
expectSafeLink(
    !$localValidator->validate("/downloads/tool.exe", LinkValidationMode::REMOTE)->safe,
    "internal download path is rejected by the shared policy",
);
expectSafeLink(
    !$localValidator->validate("/api/export", LinkValidationMode::REMOTE)->safe,
    "internal API endpoints cannot be used as navigation links",
);
expectSafeLink(
    !$localValidator->validate("/archive.sql", LinkValidationMode::REMOTE)->safe,
    "internal file extensions outside HTML are rejected",
);
expectSafeLink(
    $localValidator->validate(
        "/api/pages/14/media?key=5%2Fpages%2F14%2Fimages%2F0123456789abcdef0123456789abcdef.jpg",
        LinkValidationMode::REMOTE,
    )->safe,
    "validated internal MinIO media routes remain navigable",
);
expectSafeLink(
    !$localValidator->validate("https://example.com/tool.exe", LinkValidationMode::LOCAL)->safe,
    "external executable path is rejected locally",
);
expectSafeLink(
    !$localValidator->validate("https://example.com/tool%252Eexe", LinkValidationMode::LOCAL)->safe,
    "double-encoded executable path is rejected locally",
);
expectSafeLink(
    !$localValidator->validate("https://example.com/tool%252525252Eexe", LinkValidationMode::LOCAL)->safe,
    "repeatedly encoded executable path is rejected locally",
);
expectSafeLink(
    !$localValidator->validate("https://example.com/page?down%256coad=1", LinkValidationMode::LOCAL)->safe,
    "double-encoded download query is rejected locally",
);
expectSafeLink(
    !$localValidator->validate("https://example.com/page?download[]=1", LinkValidationMode::LOCAL)->safe,
    "array-shaped download query is rejected locally",
);
expectSafeLink(
    !$localValidator->validate("http://example.com", LinkValidationMode::LOCAL)->safe,
    "plain HTTP is rejected",
);
expectSafeLink(
    !$localValidator->validate("https://user:pass@example.com", LinkValidationMode::LOCAL)->safe,
    "URL credentials are rejected",
);

expectSafeLinkDomainException(
    static fn () => $networkValidator->resolvePublicIp($linkParser->parse("https://127.0.0.1/private")),
    "private network link is rejected",
);
expectSafeLinkDomainException(
    static fn () => $networkValidator->resolvePublicIp($linkParser->parse("https://100.64.0.1/private")),
    "CGNAT network link is rejected",
);
expectSafeLinkDomainException(
    static fn () => $networkValidator->resolvePublicIp($linkParser->parse("https://[64:ff9b::7f00:1]/private")),
    "NAT64 loopback link is rejected",
);
expectSafeLinkDomainException(
    static fn () => $networkValidator->resolvePublicIp($linkParser->parse("https://[::7f00:1]/private")),
    "IPv4-compatible loopback link is rejected",
);
expectSafeLinkDomainException(
    static fn () => $networkValidator->resolvePublicIp($linkParser->parse("https://[fec0::1]/private")),
    "deprecated IPv6 site-local network is rejected",
);
expectSafeLinkDomainException(
    static fn () => $networkValidator->resolvePublicIp($linkParser->parse("https://[2001:0000:4136:e378:8000:63bf:3fff:fdd2]/private")),
    "Teredo network is rejected",
);

$publicResolver = new class implements NetworkTargetResolver {
    public function resolvePublicIp(LinkTarget $target): string
    {
        return "93.184.216.34";
    }
};
$remoteValidator = static function (array $responses, ?NetworkTargetResolver $resolver = null) use (
    $linkParser,
    $linkPolicy,
    $signatureDetector,
    $publicResolver,
): SafeLinkValidator {
    $handler = new GuzzleHttp\Handler\MockHandler($responses);
    $client = new GuzzleHttp\Client([
        "handler" => GuzzleHttp\HandlerStack::create($handler),
        "http_errors" => false,
    ]);

    return new SafeLinkValidator(
        $linkParser,
        $linkPolicy,
        new RemoteContentInspector(
            $client,
            $linkParser,
            $linkPolicy,
            $resolver ?? $publicResolver,
            $signatureDetector,
        ),
    );
};

$mimeLie = $remoteValidator([
    new GuzzleHttp\Psr7\Response(200, ["Content-Type" => "text/html"], "{\"not\":\"html\"}"),
])->validate("https://example.com/page", LinkValidationMode::REMOTE);
expectSafeLink(!$mimeLie->safe && $mimeLie->detectedSignature === "Contenu non HTML", "lying HTML MIME is rejected");

$executableBytes = $remoteValidator([
    new GuzzleHttp\Psr7\Response(200, ["Content-Type" => "text/html"], "MZ" . str_repeat("\0", 32)),
])->validate("https://example.com/page", LinkValidationMode::REMOTE);
expectSafeLink(
    !$executableBytes->safe
        && $executableBytes->detectedSignature === "Executable Windows (MZ)"
        && str_starts_with((string) $executableBytes->signatureHex, "4D5A"),
    "executable bytes override a declared HTML MIME and retain their signature",
);

$wrongMime = $remoteValidator([
    new GuzzleHttp\Psr7\Response(200, ["Content-Type" => "application/pdf"], "<!doctype html><html></html>"),
])->validate("https://example.com/page", LinkValidationMode::REMOTE);
expectSafeLink(
    !$wrongMime->safe && $wrongMime->declaredMime === "application/pdf",
    "non-HTML MIME is rejected even when the sampled bytes look like HTML",
);

$downloadPathWithHtml = $remoteValidator([
    new GuzzleHttp\Psr7\Response(200, ["Content-Type" => "text/html"], "<!doctype html><html></html>"),
])->validate("https://example.com/tool.exe", LinkValidationMode::REMOTE);
expectSafeLink(
    !$downloadPathWithHtml->safe
        && $downloadPathWithHtml->declaredMime === "text/html"
        && $downloadPathWithHtml->detectedSignature === "Document HTML/XHTML",
    "remote executable path remains rejected while retaining MIME and byte inspection",
);

$attachment = $remoteValidator([
    new GuzzleHttp\Psr7\Response(200, [
        "Content-Type" => "text/html; charset=utf-8",
        "Content-Disposition" => "attachment; filename=page.html",
    ], "<!doctype html><html></html>"),
])->validate("https://example.com/page", LinkValidationMode::REMOTE);
expectSafeLink(
    !$attachment->safe
        && $attachment->declaredMime === "text/html"
        && $attachment->detectedSignature === "Document HTML/XHTML"
        && $attachment->signatureHex !== null,
    "forced download keeps MIME and byte details",
);

$redirectedHtml = $remoteValidator([
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/final"]),
    new GuzzleHttp\Psr7\Response(200, ["Content-Type" => "text/html"], "<!doctype html><html></html>"),
])->validate("https://example.com/start", LinkValidationMode::REMOTE);
expectSafeLink(
    $redirectedHtml->safe && $redirectedHtml->redirects === ["https://example.com/final"],
    "relative redirect is inspected and reported: " . json_encode($redirectedHtml->toArray()),
);
expectSafeLink(
    array_keys($redirectedHtml->toArray()) === [
        "safe",
        "url",
        "final_url",
        "declared_mime",
        "detected_signature",
        "signature_hex",
        "redirects",
        "message",
    ],
    "frontend inspection response contract stays stable",
);

$httpRedirect = $remoteValidator([
    new GuzzleHttp\Psr7\Response(302, ["Location" => "http://example.com/final"]),
])->validate("https://example.com/start", LinkValidationMode::REMOTE);
expectSafeLink(!$httpRedirect->safe, "HTTPS link cannot redirect to HTTP");

$redirectResolver = new class implements NetworkTargetResolver {
    public array $hosts = [];

    public function resolvePublicIp(LinkTarget $target): string
    {
        $this->hosts[] = $target->host;

        if ($target->host === "127.0.0.1") {
            throw new DomainException("Cette destination reseau est interdite");
        }

        return "93.184.216.34";
    }
};
$privateRedirect = $remoteValidator([
    new GuzzleHttp\Psr7\Response(302, ["Location" => "https://127.0.0.1/private"]),
], $redirectResolver)->validate("https://example.com/start", LinkValidationMode::REMOTE);
expectSafeLink(
    !$privateRedirect->safe && $redirectResolver->hosts === ["example.com", "127.0.0.1"],
    "every redirect target is resolved again and private destinations are rejected",
);

$threeRedirects = $remoteValidator([
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/one"]),
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/two"]),
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/three"]),
    new GuzzleHttp\Psr7\Response(200, ["Content-Type" => "text/html"], "<!doctype html><html></html>"),
])->validate("https://example.com/start", LinkValidationMode::REMOTE);
expectSafeLink(
    $threeRedirects->safe && count($threeRedirects->redirects) === 3,
    "three redirects are accepted",
);

$redirectLimit = $remoteValidator([
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/one"]),
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/two"]),
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/three"]),
    new GuzzleHttp\Psr7\Response(302, ["Location" => "/four"]),
])->validate("https://example.com/start", LinkValidationMode::REMOTE);
expectSafeLink(
    !$redirectLimit->safe && $redirectLimit->message === "La limite de 3 redirections a ete depassee",
    "fourth redirect is rejected",
);

$linkHeavyDocument = CmsContentValidator::emptyDocument();
$linkHeavyDocument["blocks"]["text-links"] = [
    "id" => "text-links",
    "type" => "text",
    "props" => [
        "content" => array_map(
            static fn (int $index): array => ["href" => "https://example.com/page-" . $index],
            range(1, 26),
        ),
    ],
];
$linkHeavyDocument["layouts"]["lg"] = [[
    "i" => "text-links",
    "parentId" => null,
    "x" => 0,
    "y" => 0,
    "w" => 12,
    "h" => 1,
]];
expectSafeLinkDomainException(
    static fn () => CmsContentValidator::validate($linkHeavyDocument, 7, 12, true),
    "publication rejects more than 25 unique external inspections before network access",
);

echo "SafeLinkValidator tests: OK\n";
