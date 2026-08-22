<?php

declare(strict_types=1);

/**
 * Parses CMS links into internal paths or external HTTPS targets.
 * SafeLinkValidator invokes it before applying download rules or performing any network request.
 */
final class LinkTargetParser
{
    public function parse(string $url): LinkTarget
    {
        if ($url === ""
            || strlen($url) > 2048
            || preg_match("/[\\x00-\\x20\\x7F\\\\]/", $url) === 1
        ) {
            throw new DomainException("Lien hypertexte invalide");
        }

        if (str_starts_with($url, "/") && !str_starts_with($url, "//")) {
            $parts = parse_url($url);

            if (!is_array($parts) || isset($parts["host"]) || isset($parts["scheme"])) {
                throw new DomainException("Lien hypertexte invalide");
            }

            return new LinkTarget(
                $url,
                true,
                "",
                "",
                (string) ($parts["path"] ?? ""),
                (string) ($parts["query"] ?? ""),
            );
        }

        $parts = parse_url($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts["scheme"] ?? "")) !== "https"
            || !isset($parts["host"])
            || isset($parts["user"])
            || isset($parts["pass"])
            || (isset($parts["port"]) && (int) $parts["port"] !== 443)
        ) {
            throw new DomainException("Seuls les liens internes et HTTPS sont autorises");
        }

        return new LinkTarget(
            $url,
            false,
            "https",
            trim(strtolower((string) $parts["host"]), "[]"),
            (string) ($parts["path"] ?? ""),
            (string) ($parts["query"] ?? ""),
        );
    }
}
