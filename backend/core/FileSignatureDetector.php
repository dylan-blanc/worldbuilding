<?php

declare(strict_types=1);

/**
 * Classifies the first bytes of remote link responses as HTML, known binary content or unknown data.
 * RemoteContentInspector accepts only a positive HTML/XHTML classification and reports the hexadecimal sample.
 */
final class FileSignatureDetector
{
    public function detect(string $bytes): DetectedFileSignature
    {
        $hex = strtoupper(bin2hex(substr($bytes, 0, 16)));
        $signatures = [
            ["prefix" => "MZ", "type" => "exe", "label" => "Executable Windows (MZ)"],
            ["prefix" => "\x7FELF", "type" => "elf", "label" => "Executable Linux (ELF)"],
            ["prefix" => "\x00asm", "type" => "wasm", "label" => "Executable WebAssembly"],
            ["prefix" => "PK\x03\x04", "type" => "zip", "label" => "Archive ZIP"],
            ["prefix" => "Rar!\x1A\x07", "type" => "rar", "label" => "Archive RAR"],
            ["prefix" => "7z\xBC\xAF\x27\x1C", "type" => "7z", "label" => "Archive 7-Zip"],
            ["prefix" => "\x1F\x8B", "type" => "gzip", "label" => "Archive GZIP"],
            ["prefix" => "%PDF-", "type" => "pdf", "label" => "Document PDF"],
            ["prefix" => "\x89PNG\x0D\x0A\x1A\x0A", "type" => "png", "label" => "Image PNG"],
            ["prefix" => "\xFF\xD8\xFF", "type" => "jpeg", "label" => "Image JPEG"],
            ["prefix" => "GIF87a", "type" => "gif", "label" => "Image GIF"],
            ["prefix" => "GIF89a", "type" => "gif", "label" => "Image GIF"],
            ["prefix" => "\x1A\x45\xDF\xA3", "type" => "ebml", "label" => "Media WebM/MKV"],
        ];

        foreach ($signatures as $signature) {
            if (str_starts_with($bytes, $signature["prefix"])) {
                return new DetectedFileSignature(
                    $signature["type"],
                    $signature["label"],
                    $hex,
                    false,
                    true,
                );
            }
        }

        if (str_starts_with($bytes, "RIFF")) {
            return new DetectedFileSignature("riff", "Media RIFF", $hex, false, true);
        }

        if (strlen($bytes) >= 12 && substr($bytes, 4, 4) === "ftyp") {
            return new DetectedFileSignature("ftyp", "Media ISO Base", $hex, false, true);
        }

        if ($this->isMachO($bytes)) {
            return new DetectedFileSignature("mach-o", "Executable Mach-O", $hex, false, true);
        }

        if ($this->isHtml($bytes)) {
            return new DetectedFileSignature("html", "Document HTML/XHTML", $hex, true, false);
        }

        return new DetectedFileSignature("unknown", "Contenu non HTML", $hex, false, false);
    }

    private function isHtml(string $bytes): bool
    {
        $sample = substr($bytes, 0, 4096);
        $sample = preg_replace("/^\xEF\xBB\xBF/", "", $sample) ?? $sample;
        $sample = ltrim($sample, "\x00\x09\x0A\x0D\x20");
        $sample = preg_replace("/^(?:<!--.{0,1024}?-->\s*)+/s", "", $sample) ?? $sample;

        if (preg_match("/^<!doctype\s+html\b/i", $sample) === 1) {
            return true;
        }

        if (preg_match("/^<html\b/i", $sample) === 1) {
            return true;
        }

        if (preg_match("/^<\?xml\b.{0,1024}?<html\b/is", $sample) === 1) {
            return true;
        }

        return preg_match("/^<(?:head|body|title|meta|link|main|header|footer|nav|section|article|div)\b/i", $sample) === 1;
    }

    private function isMachO(string $bytes): bool
    {
        $prefix = substr($bytes, 0, 4);

        return in_array($prefix, [
            "\xFE\xED\xFA\xCE",
            "\xCE\xFA\xED\xFE",
            "\xFE\xED\xFA\xCF",
            "\xCF\xFA\xED\xFE",
            "\xCA\xFE\xBA\xBE",
        ], true);
    }
}
