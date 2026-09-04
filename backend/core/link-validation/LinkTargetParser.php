<?php

declare(strict_types=1);

/**
 * Parses CMS links into internal paths or external HTTPS targets.
 * SafeLinkValidator invokes it before applying download rules or performing any network request.
 */
final class LinkTargetParser
{
    /*
     * Entrée : valeur href issue du contenu CMS ou de POST /links/inspect.
     * Traitement : séparation entre route interne commençant par / et URL externe HTTPS ; rejet des caractères de
     * contrôle, antislashs, URL //domaine, identifiants intégrés et ports externes différents de 443.
     * Sortie : LinkTarget contenant les composants utilisés par la politique de chemin et la validation réseau.
     */
    public function parse(string $url): LinkTarget
    {
        // verfie que l'URL n'est pas vide, ne dépasse pas 2048 caractères d'espace ou d'antislashs
        if ($url === ""
        // evite une URL consommant tros de mémoire (DDOS)
            || strlen($url) > 2048
            || preg_match("/[\\x00-\\x20\\x7F\\\\]/", $url) === 1
        ) {
            throw new DomainException("Lien invalide");
        }

        // verifie que l'URL est relative et commence par / mais pas par // (//domaine)
        // (lien interne) ou que l'URL est un domaine et commence par https:// (lien externe)

        if (str_starts_with($url, "/") && !str_starts_with($url, "//")) {
            $parts = parse_url($url);

            if (!is_array($parts) || isset($parts["host"]) || isset($parts["scheme"])) {
                throw new DomainException("Lien invalide");
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

        // si l'URL n'est pas un lien interne, elle doit être un lien externe HTTPS (://https://domaine) 
        // sans identifiants, empêche une redirection vers un faux domaine
        //  ni port autre que 443 = port HTTPS par défault, sinon rejet de l'URL

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts["scheme"] ?? "")) !== "https"
            || !isset($parts["host"])
            || isset($parts["user"])
            || isset($parts["pass"])
            || (isset($parts["port"]) && (int) $parts["port"] !== 443)
        ) {
            throw new DomainException("Seuls les liens HTTPS sont autorises");
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
