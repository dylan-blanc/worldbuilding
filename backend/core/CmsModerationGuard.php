<?php

declare(strict_types=1);

/**
 * Prevents an owner from restoring moderated CMS content without replacing it.
 * PageRevision::saveDraft() compares the stored draft to the submitted JSON; a marked block may remain marked,
 * be deleted, or lose moderationRemoved only after its text changes or a new validated MinIO key is supplied.
 */
final class CmsModerationGuard
{
    private const MEDIA_TYPES = ["image", "banner", "gallery", "video"];

    public static function validateReplacement(string $previousJson, string $nextJson): void
    {
        $previous = json_decode($previousJson, true, 512, JSON_THROW_ON_ERROR);
        $next = json_decode($nextJson, true, 512, JSON_THROW_ON_ERROR);
        $previousBlocks = is_array($previous) && is_array($previous["blocks"] ?? null)
            ? $previous["blocks"]
            : [];
        $nextBlocks = is_array($next) && is_array($next["blocks"] ?? null)
            ? $next["blocks"]
            : [];

        foreach ($previousBlocks as $blockId => $previousBlock) {
            if (!is_array($previousBlock)
                || ($previousBlock["props"]["moderationRemoved"] ?? false) !== true
                || !isset($nextBlocks[$blockId])
            ) {
                continue;
            }

            $nextBlock = $nextBlocks[$blockId];

            if (!is_array($nextBlock)
                || ($nextBlock["props"]["moderationRemoved"] ?? false) === true
            ) {
                continue;
            }

            $type = (string) ($previousBlock["type"] ?? "");

            if (($nextBlock["type"] ?? null) !== $type) {
                throw new DomainException("Le type d'un bloc modere ne peut pas etre contourne");
            }

            if ($type === "text") {
                $previousContent = json_encode($previousBlock["props"]["content"] ?? null, JSON_THROW_ON_ERROR);
                $nextContent = json_encode($nextBlock["props"]["content"] ?? null, JSON_THROW_ON_ERROR);

                if ($previousContent === $nextContent) {
                    throw new DomainException("Le texte modere doit etre modifie avant publication");
                }

                continue;
            }

            if (in_array($type, self::MEDIA_TYPES, true)
                && trim((string) ($nextBlock["props"]["objectKey"] ?? "")) !== ""
            ) {
                continue;
            }

            throw new DomainException("Le contenu modere doit etre remplace ou supprime");
        }
    }
}
