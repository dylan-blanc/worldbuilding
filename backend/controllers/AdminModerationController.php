<?php

declare(strict_types=1);

/**
 * Serves the moderation workspace used by frontend/app/views/adminmoderation.vue.
 * GET lists reports by status or page history after admin authorization.
 * PATCH validates a workflow status, then Moderation::updateStatus() records the reviewing admin.
 */
final class AdminModerationController
{
    private const STATUSES = ["pending", "reviewed", "dismissed"];

    private Moderation $moderation;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->moderation = new Moderation($pdo);
        $this->users = new User($pdo);
    }

    public function index(): void
    {
        $this->requireAdmin();
        $status = (string) ($_GET["status"] ?? "pending");
        $this->validateStatus($status);

        Response::json(200, [
            "reports" => $this->moderation->findByStatus($status),
        ]);
    }

    public function history(int $pageId): void
    {
        $this->requireAdmin();

        Response::json(200, [
            "reports" => $this->moderation->findHistory($pageId, "page_display"),
        ]);
    }

    public function updateStatus(int $id): void
    {
        Request::requireSameOrigin();
        $adminUserId = $this->requireAdmin();
        $status = Request::field(Request::body(), ["moderation_status", "status"]);
        $this->validateStatus($status);
        $report = $this->moderation->findById($id);

        if ($report === null) {
            Response::error("Signalement introuvable", 404, "moderation_report_not_found");
        }

        $updatedReport = $this->moderation->updateStatus($id, $status, $adminUserId);

        Response::json(200, [
            "message" => "Statut du signalement mis a jour",
            "report" => $updatedReport,
        ]);
    }

    private function requireAdmin(): int
    {
        $userId = Session::userId();

        if ($userId === null || !$this->users->isAdmin($userId)) {
            Response::error("Acces refuse", 403, "access_denied");
        }

        return $userId;
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            Response::error("Statut de moderation invalide", 422, "invalid_moderation_status");
        }
    }
}
