<?php

declare(strict_types=1);

/**
 * Centralizes read access to published page JSON and its MinIO media.
 * PageController::show(), PageMediaController and moderation previews provide page status,
 * viewer identity and the database-backed admin flag before returning JSON or media.
 */
final class PageReadAccess
{
    public static function allows(array $page, ?int $viewerUserId, bool $viewerIsAdmin): bool
    {
        $status = (string) ($page["page_status"] ?? "");
        $ownerUserId = (int) ($page["owner_user_id"] ?? 0);

        return ($status === "public")
            || $viewerIsAdmin
            || ($status === "private" && $viewerUserId === $ownerUserId);
    }
}
