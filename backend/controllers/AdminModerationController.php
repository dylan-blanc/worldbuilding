<?php

declare(strict_types=1);

/**
 * Serves grouped page moderation cases to frontend/app/views/adminmoderation.vue.
 * GET /api/admin/moderation returns cases with child reports; the context endpoint compares snapshots.
 * PATCH case or report endpoints records the global or individual administrator decision through Moderation.
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
        $cases = array_map(function (array $case): array {
            $case["reports"] = $this->normalizedReports(
                $this->moderation->findReportsByCaseId((int) $case["id"])
            );

            return $case;
        }, $this->moderation->findCasesByStatus($status));

        Response::json(200, [
            "cases" => $cases,
        ]);
    }

    public function context(int $caseId): void
    {
        $this->requireAdmin();
        $case = $this->moderation->findCaseById($caseId);

        if ($case === null) {
            Response::error("Dossier de moderation introuvable", 404, "moderation_case_not_found");
        }

        $pageId = (int) $case["reported_page_id"];
        $page = $this->moderation->findPageContext($pageId);

        if ($page === null) {
            Response::error("Page introuvable", 404, "reported_page_not_found");
        }

        $page["pagecontent"] = $this->decodedJson($page["pagecontent"]);
        $case["reported_page_snapshot"] = $this->decodedJson($case["reported_page_snapshot"]);
        $revisions = array_map(function (array $revision): array {
            $revision["pagecontent"] = $this->decodedJson($revision["pagecontent"]);

            return $revision;
        }, $this->moderation->findPageRevisionHistory($pageId));

        Response::json(200, [
            "case" => $case,
            "page" => $page,
            "revisions" => $revisions,
            "reports" => $this->normalizedReports($this->moderation->findReportsByCaseId($caseId)),
        ]);
    }

    public function updateReportStatus(int $id): void
    {
        Request::requireSameOrigin();
        $adminUserId = $this->requireAdmin();
        $status = Request::field(Request::body(), ["moderation_status", "status"]);
        $this->validateStatus($status);

        if ($this->moderation->findById($id) === null) {
            Response::error("Signalement introuvable", 404, "moderation_report_not_found");
        }

        Response::json(200, [
            "message" => "Statut du signalement mis a jour",
            "report" => $this->normalizedReport(
                $this->moderation->updateReportStatus($id, $status, $adminUserId)
            ),
        ]);
    }

    public function updateCaseStatus(int $id): void
    {
        Request::requireSameOrigin();
        $adminUserId = $this->requireAdmin();
        $status = Request::field(Request::body(), ["moderation_status", "status"]);
        $this->validateStatus($status);

        if ($this->moderation->findCaseById($id) === null) {
            Response::error("Dossier de moderation introuvable", 404, "moderation_case_not_found");
        }

        Response::json(200, [
            "message" => "Statut global de la page mis a jour",
            "case" => $this->moderation->updateCaseStatus($id, $status, $adminUserId),
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

    private function normalizedReports(array $reports): array
    {
        return array_map(fn (array $report): array => $this->normalizedReport($report), $reports);
    }

    private function normalizedReport(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        $snapshot = $report["reported_content_snapshot"] ?? null;
        $report["reported_content_snapshot"] = is_string($snapshot) && $snapshot !== ""
            ? $this->decodedJson($snapshot)
            : null;

        return $report;
    }

    private function decodedJson(mixed $json): mixed
    {
        return is_string($json)
            ? json_decode($json, true, 512, JSON_THROW_ON_ERROR)
            : $json;
    }
}
