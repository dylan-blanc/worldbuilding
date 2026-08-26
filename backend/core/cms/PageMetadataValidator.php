<?php

declare(strict_types=1);

/**
 * Validates page titles and filter selections submitted by page owners.
 * PageController uses it for POST /pages, POST /me/pages/{id}/settings, PUT /pages/{id}/metadata and POST /pages/{id}/publish
 * before Page, PageFilter or PageRevision writes metadata to pages and page_filters.
 */
final class PageMetadataValidator
{
    public const MAX_FILTERS = 15;
    private const MAX_TITLE_LENGTH = 255;

    public static function title(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new DomainException("Titre de page invalide");
        }

        $title = trim((string) $value);

        if ($title === "") {
            throw new DomainException("Titre de page requis");
        }

        if (strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new DomainException("Titre de page trop long");
        }

        if (self::containsUrl($title)) {
            throw new DomainException("Les URL sont interdites dans le titre de la page");
        }

        return $title;
    }

    public static function filterIds(mixed $value): array
    {
        if (!is_array($value)) {
            throw new DomainException("Selection de filtres invalide");
        }

        $ids = [];

        foreach ($value as $filterId) {
            if (!is_int($filterId) || $filterId < 1) {
                throw new DomainException("Selection de filtres invalide");
            }

            $ids[$filterId] = $filterId;
        }

        $ids = array_values($ids);

        if (count($ids) < 1 || count($ids) > self::MAX_FILTERS) {
            throw new DomainException("Selectionnez entre 1 et 15 filtres");
        }

        return $ids;
    }

    public static function containsUrl(string $value): bool
    {
        return preg_match(
            "~(?:https?://|www\\.|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+(?:com|net|org|io|fr|be|ch|ca|de|es|it|uk|eu|dev|app|online|site|xyz)(?:[/:?#]|\\b))~iu",
            $value
        ) === 1;
    }
}
