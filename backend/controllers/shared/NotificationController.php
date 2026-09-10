<?php

declare(strict_types=1);

/**
 * Exposes authenticated moderation notifications without including them in public page payloads.
 * /profil and /profil/notification call GET counter/list routes, while PATCH /me/notifications/{id}/read
 * follows session user -> UserNotification owner-scoped SQL -> normalized private JSON response.
 */
final class NotificationController
{
    private UserNotification $notifications;

    public function __construct(PDO $pdo)
    {
        $this->notifications = new UserNotification($pdo);
    }

    public function index(): void
    {
        $userId = $this->authenticatedUserId();

        Response::json(200, [
            "notifications" => $this->notifications->findByUser($userId),
            "unread_count" => $this->notifications->unreadCount($userId),
        ]);
    }

    public function unreadCount(): void
    {
        Response::json(200, [
            "unread_count" => $this->notifications->unreadCount($this->authenticatedUserId()),
        ]);
    }

    public function markRead(int $notificationId): void
    {
        Request::requireSameOrigin();
        $notification = $this->notifications->markRead($notificationId, $this->authenticatedUserId());

        if ($notification === null) {
            Response::error("Notification introuvable", 404, "notification_not_found");
        }

        Response::json(200, [
            "notification" => $notification,
        ]);
    }

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Non authentifie", 401, "authentication_required");
        }

        Session::renewForMutation();

        return $userId;
    }
}
