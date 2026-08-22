<?php

declare(strict_types=1);

/**
 * Rejects deterministic download targets without contacting their server.
 * Draft saves, Tiptap inspection and publication all use this same policy through SafeLinkValidator.
 */
final class LinkNavigationPolicy
{
    private const INTERNAL_HTML_EXTENSIONS = ["html", "htm"];
    private const DOWNLOAD_EXTENSIONS = [
        "7z", "apk", "appx", "avi", "bat", "bin", "bz2", "cmd", "com", "csv", "deb", "dmg",
        "doc", "docm", "docx", "elf", "exe", "flac", "gif", "gz", "ico", "iso", "jar", "jpeg",
        "jpg", "m4a", "mkv", "mov", "mp3", "mp4", "msi", "msix", "odp", "ods", "odt", "ogg",
        "pdf", "pkg", "png", "ppt", "pptm", "pptx", "ps1", "rar", "rpm", "rtf", "sh", "svg",
        "tar", "tgz", "tif", "tiff", "txt", "wav", "wasm", "webm", "webp", "xls", "xlsm", "xlsx", "xz", "zip",
    ];

    public function assertAllowed(LinkTarget $target): void
    {
        if ($this->isDirectDownload($target)) {
            throw new DomainException("Les liens directs vers des fichiers telechargeables sont interdits");
        }
    }

    public function isDirectDownload(LinkTarget $target): bool
    {
        $decodedPath = $this->decodeRepeatedly($target->path);
        $decodedQuery = $this->decodeRepeatedly($target->query);
        $extension = strtolower(pathinfo($decodedPath, PATHINFO_EXTENSION));
        $downloadQuery = preg_match(
            "/(?:^|[&;])(download|attachment|file|filename)(?:\[[^\]]*\])?(?:=|&|;|$)/i",
            $decodedQuery
        ) === 1;
        $downloadPath = preg_match("#/(downloads?|attachments?)(?:/|$)#i", $decodedPath) === 1;
        $forbiddenInternalTarget = $target->isInternal()
            && (($extension !== "" && !in_array($extension, self::INTERNAL_HTML_EXTENSIONS, true))
                || $this->isForbiddenInternalApiRoute($decodedPath));

        return $forbiddenInternalTarget
            || ($extension !== "" && in_array($extension, self::DOWNLOAD_EXTENSIONS, true))
            || $downloadQuery
            || $downloadPath;
    }

    private function isForbiddenInternalApiRoute(string $path): bool
    {
        if (preg_match("#^/api(?:/|$)#i", $path) !== 1) {
            return false;
        }

        return preg_match(
            "#^/api/(?:me/picture|pages/[0-9]+/(?:media|picture|owner-picture))/?$#i",
            $path,
        ) !== 1;
    }

    private function decodeRepeatedly(string $value): string
    {
        while (true) {
            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                return $value;
            }

            $value = $decoded;
        }
    }
}
