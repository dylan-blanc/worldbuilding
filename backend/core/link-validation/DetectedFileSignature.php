<?php

declare(strict_types=1);

/**
 * Describes the first-byte classification produced by FileSignatureDetector.
 * RemoteContentInspector requires an HTML signature in addition to an allowed HTTP MIME type.
 */
final readonly class DetectedFileSignature
{
    public function __construct(
        public string $type,
        public string $label,
        public string $hex,
        public bool $html,
        public bool $forbidden,
    ) {
    }
}
