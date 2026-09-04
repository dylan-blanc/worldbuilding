<?php

declare(strict_types=1);

/**
 * Represents the link decision shared by Tiptap, draft validation and publication.
 * LinkController serializes the accepted URL and rejection message for the Nuxt frontend.
 */
final readonly class LinkValidationResult
{
    /*
     * partagé par SafeLinkValidator, LinkController et useSafeLink.
     * safe contient la décision, url la valeur contrôlée et message la cause du rejet ou la confirmation.
     */
    public function __construct(
        public bool $safe,
        public string $url,
        public string $message,
    ) {
    }


    public static function acceptedLocally(LinkTarget $target): self
    {
        return new self(
            true,
            $target->originalUrl,
            $target->isInternal() ? "Lien interne autorise" : "Lien HTTPS autorise localement",
        );
    }

    public static function rejected(
        string $url,
        string $message,
    ): self {
        return new self(
            false,
            $url,
            $message,
        );
    }

    public function toArray(): array
    {
        return [
            "safe" => $this->safe,
            "url" => $this->url,
            "message" => $this->message,
        ];
    }
}
