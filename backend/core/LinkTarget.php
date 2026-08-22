<?php

declare(strict_types=1);

/**
 * Carries the normalized components of one CMS navigation target.
 * LinkTargetParser creates it before policies, network resolution and remote content inspection consume it.
 */
final readonly class LinkTarget
{
    public function __construct(
        public string $originalUrl,
        public bool $internal,
        public string $scheme,
        public string $host,
        public string $path,
        public string $query,
    ) {
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }
}
