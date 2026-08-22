<?php

declare(strict_types=1);

/**
 * Represents the stable safe-link response shared by Tiptap and publication validation.
 * LinkController serializes it without exposing network implementation details to the frontend.
 */
final readonly class LinkValidationResult
{
    public function __construct(
        public bool $safe,
        public string $url,
        public string $finalUrl,
        public ?string $declaredMime,
        public string $detectedSignature,
        public ?string $signatureHex,
        public array $redirects,
        public string $message,
    ) {
    }

    public static function acceptedLocally(LinkTarget $target): self
    {
        return new self(
            true,
            $target->originalUrl,
            $target->originalUrl,
            null,
            $target->isInternal() ? "Route interne" : "Validation locale",
            null,
            [],
            $target->isInternal() ? "Lien interne autorise" : "Lien HTTPS autorise localement",
        );
    }

    public static function rejected(
        string $url,
        string $message,
        ?string $finalUrl = null,
        ?string $declaredMime = null,
        string $detectedSignature = "Indisponible",
        ?string $signatureHex = null,
        array $redirects = [],
    ): self {
        return new self(
            false,
            $url,
            $finalUrl ?? $url,
            $declaredMime,
            $detectedSignature,
            $signatureHex,
            $redirects,
            $message,
        );
    }

    public function toArray(): array
    {
        return [
            "safe" => $this->safe,
            "url" => $this->url,
            "final_url" => $this->finalUrl,
            "declared_mime" => $this->declaredMime,
            "detected_signature" => $this->detectedSignature,
            "signature_hex" => $this->signatureHex,
            "redirects" => $this->redirects,
            "message" => $this->message,
        ];
    }
}
