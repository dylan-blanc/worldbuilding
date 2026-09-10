<?php

declare(strict_types=1);

/**
 * Reads and acknowledges private notifications created by administrator moderation decisions.
 * NotificationController uses this model for GET /api/me/notifications, its unread counter and the per-item
 * read endpoint; every query is scoped to Session::userId() before JSON reaches /profil/notification.
 */
final class UserNotification
{
    public function __construct(private PDO $pdo) {}

    public function findByUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, page_id, notification_type, title, message, details, read_at, created_at
            FROM user_notifications
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            LIMIT 200"
        );
        $statement->execute([":user_id" => $userId]);

        return array_map([$this, "normalize"], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function unreadCount(int $userId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND read_at IS NULL"
        );
        $statement->execute([":user_id" => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function markRead(int $notificationId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "UPDATE user_notifications
            SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
            WHERE id = :id AND user_id = :user_id"
        );
        $statement->execute([
            ":id" => $notificationId,
            ":user_id" => $userId,
        ]);

        return $this->findOwned($notificationId, $userId);
    }

    private function findOwned(int $notificationId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, page_id, notification_type, title, message, details, read_at, created_at
            FROM user_notifications
            WHERE id = :id AND user_id = :user_id
            LIMIT 1"
        );
        $statement->execute([
            ":id" => $notificationId,
            ":user_id" => $userId,
        ]);
        $notification = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($notification) ? $this->normalize($notification) : null;
    }

    private function normalize(array $notification): array
    {
        $notification["id"] = (int) $notification["id"];
        $notification["page_id"] = $notification["page_id"] === null
            ? null
            : (int) $notification["page_id"];
        $notification["details"] = json_decode((string) $notification["details"], true, 512, JSON_THROW_ON_ERROR);

        return $notification;
    }
}
