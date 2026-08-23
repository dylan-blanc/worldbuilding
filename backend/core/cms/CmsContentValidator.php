<?php

declare(strict_types=1);

/**
 * Validates CMS JSON before PageRevision writes it to page_revision or publishes it to pages.pagecontent.
 * PageController passes PUT /pages/{id}/draft and POST /pages/{id}/publish data through this allow-list.
 * Links use SafeLinkValidator locally for drafts and remotely for publication before JSON reaches SQL.
 */
final class CmsContentValidator
{
    private const MAX_DOCUMENT_BYTES = 2_000_000;
    private const MAX_BLOCKS = 200;
    private const MAX_REMOTE_LINKS = 25;
    private const MAX_STRING_LENGTH = 200_000;
    private const BLOCK_TYPES = ["section", "text", "image", "banner", "gallery", "video", "separator"];
    private const BREAKPOINTS = ["lg", "md", "sm", "xs"];
    private const DOCUMENT_KEYS = ["schemaVersion", "settings", "blocks", "layouts"];
    private const SETTINGS_KEYS = ["desktopColumns", "responsiveStrategy"];
    private const BLOCK_KEYS = ["id", "type", "props"];
    private const LAYOUT_KEYS = ["i", "parentId", "x", "y", "w", "h", "minW", "minH", "maxW", "maxH"];
    private const DANGEROUS_KEYS = ["download", "html", "rawhtml", "innerhtml", "srcdoc", "script", "style", "css"];
    private const DANGEROUS_NODE_TYPES = ["script", "iframe", "object", "embed", "style", "html"];
    private const FONT_FAMILIES = ["sans-serif", "serif", "monospace"];
    private const MIN_FONT_SIZE = 8;
    private const MAX_FONT_SIZE = 256;
    private const LINE_HEIGHTS = ["1", "1.25", "1.5", "1.75", "2"];

    public static function emptyDocument(): array
    {
        return [
            "schemaVersion" => 1,
            "settings" => [
                "desktopColumns" => 12,
                "responsiveStrategy" => "auto-stack",
            ],
            "blocks" => [],
            "layouts" => [
                "lg" => [],
                "md" => [],
                "sm" => [],
                "xs" => [],
            ],
        ];
    }

    public static function validate(
        array $document,
        int $ownerUserId,
        int $pageId,
        bool $inspectExternalLinks = false
    ): array
    {
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_DOCUMENT_BYTES) {
            throw new DomainException("Le contenu de la page depasse 2 Mo");
        }

        if (array_diff(array_keys($document), self::DOCUMENT_KEYS) !== []
            || array_diff(self::DOCUMENT_KEYS, array_keys($document)) !== []
        ) {
            throw new DomainException("Structure racine du document CMS invalide");
        }

        if (($document["schemaVersion"] ?? null) !== 1) {
            throw new DomainException("Version du document CMS invalide");
        }

        self::validateSettings($document["settings"] ?? null);
        $blocks = $document["blocks"] ?? null;
        $layouts = $document["layouts"] ?? null;

        if (!is_array($blocks)
            || !is_array($layouts)
            || count($blocks) > self::MAX_BLOCKS
            || array_diff(array_keys($layouts), self::BREAKPOINTS) !== []
        ) {
            throw new DomainException("Structure des blocs CMS invalide");
        }

        $inspectExternalLinks && self::assertRemoteLinkBudget($blocks);

        $inspectedLinks = [];
        $linkValidator = SafeLinkValidator::createDefault();

        foreach ($blocks as $id => $block) {
            self::validateBlock(
                (string) $id,
                $block,
                $ownerUserId,
                $pageId,
                $inspectExternalLinks,
                $inspectedLinks,
                $linkValidator,
            );
        }

        self::validateLayouts($layouts, $blocks);

        return $document;
    }

    private static function validateSettings(mixed $settings): void
    {
        if (!is_array($settings)
            || array_diff(array_keys($settings), self::SETTINGS_KEYS) !== []
            || array_diff(self::SETTINGS_KEYS, array_keys($settings)) !== []
            || ($settings["desktopColumns"] ?? null) !== 12
            || ($settings["responsiveStrategy"] ?? null) !== "auto-stack"
        ) {
            throw new DomainException("Parametres du document CMS invalides");
        }
    }

    private static function validateBlock(
        string $id,
        mixed $block,
        int $ownerUserId,
        int $pageId,
        bool $inspectExternalLinks,
        array &$inspectedLinks,
        SafeLinkValidator $linkValidator,
    ): void
    {
        if (!preg_match("/^[A-Za-z0-9-]{1,64}$/", $id)
            || !is_array($block)
            || array_diff(array_keys($block), self::BLOCK_KEYS) !== []
            || array_diff(self::BLOCK_KEYS, array_keys($block)) !== []
            || ($block["id"] ?? null) !== $id
            || !in_array($block["type"] ?? null, self::BLOCK_TYPES, true)
            || !is_array($block["props"] ?? null)
        ) {
            throw new DomainException("Bloc CMS invalide: " . $id);
        }

        self::validateProperties(
            $block["props"],
            $ownerUserId,
            $pageId,
            0,
            $inspectExternalLinks,
            $inspectedLinks,
            $linkValidator,
        );
    }

    private static function validateProperties(
        array $properties,
        int $ownerUserId,
        int $pageId,
        int $depth,
        bool $inspectExternalLinks,
        array &$inspectedLinks,
        SafeLinkValidator $linkValidator,
    ): void
    {
        if ($depth > 12) {
            throw new DomainException("Proprietes CMS trop profondes");
        }

        foreach ($properties as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (str_starts_with($normalizedKey, "on") || in_array($normalizedKey, self::DANGEROUS_KEYS, true)) {
                throw new DomainException("Propriete CMS interdite: " . $key);
            }

            if ($normalizedKey === "type" && is_string($value) && in_array(strtolower($value), self::DANGEROUS_NODE_TYPES, true)) {
                throw new DomainException("Type de contenu enrichi interdit");
            }

            if (in_array($normalizedKey, ["href", "url", "link"], true)) {
                self::validateLink($value, $inspectExternalLinks, $inspectedLinks, $linkValidator);
            }

            if ($normalizedKey === "objectkey" && $value !== null && $value !== "") {
                self::validateObjectKey($value, $ownerUserId, $pageId);
            }

            self::validateTextFormattingAttribute($normalizedKey, $value);

            if (is_array($value)) {
                self::validateProperties(
                    $value,
                    $ownerUserId,
                    $pageId,
                    $depth + 1,
                    $inspectExternalLinks,
                    $inspectedLinks,
                    $linkValidator,
                );
                continue;
            }

            if (is_string($value)) {
                if (strlen($value) > self::MAX_STRING_LENGTH || preg_match("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/", $value)) {
                    throw new DomainException("Texte CMS invalide ou trop long");
                }

                continue;
            }

            if ($value !== null && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new DomainException("Valeur CMS invalide");
            }
        }
    }

    private static function validateLink(
        mixed $value,
        bool $inspectExternalLinks,
        array &$inspectedLinks,
        SafeLinkValidator $linkValidator,
    ): void
    {
        if (!is_string($value)) {
            throw new DomainException("Lien hypertexte invalide");
        }

        if (isset($inspectedLinks[$value])) {
            return;
        }

        $mode = $inspectExternalLinks ? LinkValidationMode::REMOTE : LinkValidationMode::LOCAL;
        $result = $linkValidator->validate($value, $mode);

        if (!$result->safe) {
            throw new DomainException($result->message);
        }

        $inspectedLinks[$value] = true;
    }

    private static function assertRemoteLinkBudget(array $blocks): void
    {
        $externalLinks = [];

        foreach ($blocks as $block) {
            if (is_array($block) && is_array($block["props"] ?? null)) {
                self::collectExternalLinks($block["props"], 0, $externalLinks);
            }
        }

        if (count($externalLinks) > self::MAX_REMOTE_LINKS) {
            throw new DomainException("La page contient trop de liens externes a verifier (25 maximum)");
        }
    }

    private static function collectExternalLinks(mixed $value, int $depth, array &$externalLinks): void
    {
        if (!is_array($value) || $depth > 12) {
            return;
        }

        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, ["href", "url", "link"], true)
                && is_string($child)
                && (!str_starts_with($child, "/") || str_starts_with($child, "//"))
            ) {
                $externalLinks[$child] = true;
            }

            is_array($child) && self::collectExternalLinks($child, $depth + 1, $externalLinks);
        }
    }

    private static function validateObjectKey(mixed $value, int $ownerUserId, int $pageId): void
    {
        if (!is_string($value)
            || !preg_match(
                "#^" . preg_quote((string) $ownerUserId, "#") . "/pages/" . preg_quote((string) $pageId, "#") . "/(images|videos)/[a-f0-9]{32}\\.(jpg|png|webp|avif|gif|mp4|webm)$#",
                $value
            )
        ) {
            throw new DomainException("Cle media MinIO invalide");
        }
    }

    private static function validateTextFormattingAttribute(string $key, mixed $value): void
    {
        if (in_array($key, ["color", "backgroundcolor"], true)
            && $value !== null
            && (!is_string($value) || preg_match("/^#[0-9a-fA-F]{6}$/", $value) !== 1)
        ) {
            throw new DomainException("Couleur de texte invalide");
        }

        if ($key === "textalign"
            && $value !== null
            && (!is_string($value) || !in_array($value, ["left", "center", "right", "justify"], true))
        ) {
            throw new DomainException("Alignement de texte invalide");
        }

        if ($key === "fontfamily"
            && $value !== null
            && (!is_string($value) || !in_array($value, self::FONT_FAMILIES, true))
        ) {
            throw new DomainException("Police de texte invalide");
        }

        if ($key === "fontsize"
            && $value !== null
            && !self::isValidFontSize($value)
        ) {
            throw new DomainException("Taille de texte invalide");
        }

        if ($key === "lineheight"
            && $value !== null
            && (!is_string($value) || !in_array($value, self::LINE_HEIGHTS, true))
        ) {
            throw new DomainException("Interligne invalide");
        }

        if ($key === "level" && (!is_int($value) || $value < 1 || $value > 6)) {
            throw new DomainException("Niveau de titre invalide");
        }

        if ($key === "target" && $value !== null && !in_array($value, ["_blank", "_self"], true)) {
            throw new DomainException("Cible de lien invalide");
        }

        if ($key === "rel"
            && $value !== null
            && (!is_string($value) || !in_array($value, ["noopener noreferrer", "noopener noreferrer nofollow"], true))
        ) {
            throw new DomainException("Attribut de lien invalide");
        }

        if (in_array($key, ["class", "classname"], true) && $value !== null && $value !== "") {
            throw new DomainException("Classe CSS utilisateur interdite");
        }
    }

    // Font sizes are persisted by Tiptap as canonical integer pixel strings shared with CmsTextBlockEditor.
    private static function isValidFontSize(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match("/^([1-9][0-9]{0,2})px$/D", $value, $matches) !== 1
        ) {
            return false;
        }

        $fontSize = (int) $matches[1];

        return $fontSize >= self::MIN_FONT_SIZE && $fontSize <= self::MAX_FONT_SIZE;
    }

    private static function validateLayouts(array $layouts, array $blocks): void
    {
        foreach (self::BREAKPOINTS as $breakpoint) {
            if (!array_key_exists($breakpoint, $layouts) || !is_array($layouts[$breakpoint])) {
                throw new DomainException("Layout responsive manquant: " . $breakpoint);
            }

            $seen = [];

            foreach ($layouts[$breakpoint] as $item) {
                self::validateLayoutItem($item, $blocks, $seen, $breakpoint);
                $seen[] = $item["i"];
            }

            foreach ($layouts[$breakpoint] as $item) {
                $parentId = $item["parentId"] ?? null;

                if ($parentId !== null && !in_array($parentId, $seen, true)) {
                    throw new DomainException("Le container parent doit exister dans le meme layout");
                }
            }
        }

        $desktopIds = array_column($layouts["lg"], "i");

        if (count($desktopIds) !== count($blocks) || array_diff(array_keys($blocks), $desktopIds) !== []) {
            throw new DomainException("Chaque bloc doit apparaitre une fois dans le layout desktop");
        }
    }

    private static function validateLayoutItem(mixed $item, array $blocks, array $seen, string $breakpoint): void
    {
        if (!is_array($item) || array_diff(array_keys($item), self::LAYOUT_KEYS) !== []) {
            throw new DomainException("Element de layout invalide");
        }

        foreach (["i", "x", "y", "w", "h"] as $requiredKey) {
            if (!array_key_exists($requiredKey, $item)) {
                throw new DomainException("Coordonnees de layout incompletes");
            }
        }

        $id = $item["i"];

        if (!is_string($id) || !array_key_exists($id, $blocks)) {
            throw new DomainException("Reference de bloc inconnue dans le layout " . $breakpoint);
        }

        if (in_array($id, $seen, true)) {
            throw new DomainException("Reference de bloc dupliquee dans le layout " . $breakpoint . ": " . $id);
        }

        $parentId = $item["parentId"] ?? null;

        if ($parentId !== null
            && (!is_string($parentId)
                || $parentId === $id
                || !isset($blocks[$parentId])
                || ($blocks[$parentId]["type"] ?? null) !== "section"
                || ($blocks[$id]["type"] ?? null) === "section")
        ) {
            throw new DomainException("Container parent invalide");
        }

        foreach (["x", "y", "w", "h", "minW", "minH", "maxW", "maxH"] as $numericKey) {
            if (array_key_exists($numericKey, $item) && !is_int($item[$numericKey])) {
                throw new DomainException("Coordonnee de layout invalide");
            }
        }

        if ($item["x"] < 0 || $item["x"] > 11 || $item["y"] < 0 || $item["y"] > 10_000
            || $item["w"] < 1 || $item["w"] > 12 || $item["h"] < 1 || $item["h"] > 200
            || $item["x"] + $item["w"] > 12
        ) {
            throw new DomainException("Dimensions XYWH hors de la grille");
        }
    }
}
