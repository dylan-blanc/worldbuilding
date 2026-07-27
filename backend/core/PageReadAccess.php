<?php

declare(strict_types=1);

/**
 * Centralizes read access to published page JSON and its MinIO media.
 * PageController::show() and PageMediaController::show() provide the page status,
 * viewer identity and database-backed admin flag before returning content.
 */
final class PageReadAccess
{
    public static function allows(array $page, ?int $viewerUserId, bool $viewerIsAdmin): bool
    {
        $status = (string) ($page["page_status"] ?? "");
        $ownerUserId = (int) ($page["owner_user_id"] ?? 0);

        return ($status === "public")
            || ($status === "private" && $viewerUserId === $ownerUserId)
            || ($status === "banned" && $viewerIsAdmin);
    }
}
