<?php

declare(strict_types=1);

/**
 * Serves grouped page moderation cases to frontend/app/views/adminmoderation.vue.
 * GET /api/admin/moderation returns cases with child reports; /users returns owner groups without JSON.
 * POST action endpoints call ModerationDecision to dismiss reports or remove current page content, create the
 * owner's private SQL notification, and enqueue deferred MinIO deletion in one transaction.
 */
final class AdminModerationController
{
    private const STATUSES = ["pending", "reviewed", "dismissed"];

    private Moderation $moderation;
    private ModerationDecision $decisions;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->moderation = new Moderation($pdo);
        $this->decisions = new ModerationDecision($pdo);
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

    public function users(): void
    {
        $this->requireAdmin();
        $owners = [];

        foreach ($this->moderation->findReportsByPageOwner() as $row) {
            $ownerId = (int) $row["owner_user_id"];
            $caseId = (int) $row["moderation_case_id"];
            $owners[$ownerId] ??= [
                "owner_user_id" => $ownerId,
                "owner_username" => (string) $row["owner_username"],
                "owner_picture" => $row["owner_picture"],
                "cases" => [],
            ];
            $owners[$ownerId]["cases"][$caseId] ??= [
                "id" => $caseId,
                "moderation_status" => (string) $row["case_status"],
                "created_at" => (string) $row["case_created_at"],
                "updated_at" => (string) $row["case_updated_at"],
                "reported_page_id" => (int) $row["page_id"],
                "page_title" => (string) $row["page_title"],
                "page_status" => (string) $row["page_status"],
                "reports" => [],
            ];
            $owners[$ownerId]["cases"][$caseId]["reports"][] = [
                "id" => (int) $row["report_id"],
                "moderation_status" => (string) $row["report_status"],
                "reported_content_type" => (string) $row["reported_content_type"],
                "reported_block_id" => (string) $row["reported_block_id"],
                "reported_block_type" => $row["reported_block_type"],
                "reported_user_message" => $row["reported_user_message"],
                "reported_filter_name" => (string) $row["reported_filter_name"],
                "reporter_user_id" => (int) $row["reporter_user_id"],
                "reporter_username" => (string) $row["reporter_username"],
                "created_at" => (string) $row["report_created_at"],
            ];
        }

        $normalizedOwners = array_map(static function (array $owner): array {
            $owner["cases"] = array_values($owner["cases"]);

            return $owner;
        }, array_values($owners));

        Response::json(200, [
            "owners" => $normalizedOwners,
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

    public function dismissReport(int $id): void
    {
        Request::requireSameOrigin();
        $adminUserId = $this->requireAdmin();

        try {
            $decision = $this->decisions->dismissReport($id, $adminUserId);
        } catch (DomainException $exception) {
            $this->decisionError($exception);
        }

        Response::json(200, [
            "message" => "Signalement ignore",
            "decision" => $decision,
        ]);
    }

    public function dismissCase(int $id): void
    {
        Request::requireSameOrigin();
        $adminUserId = $this->requireAdmin();

        try {
            $decision = $this->decisions->dismissCase($id, $adminUserId);
        } catch (DomainException $exception) {
            $this->decisionError($exception);
        }

        Response::json(200, [
            "message" => "Signalements en attente ignores et dossier clos",
            "decision" => $decision,
        ]);
    }

    public function removeReportedContent(int $id): void
    {
        Request::requireSameOrigin();
        $adminUserId = $this->requireAdmin();

        try {
            $decision = $this->decisions->removeReportedContent($id, $adminUserId);
        } catch (DomainException $exception) {
            $this->decisionError($exception);
        }

        Response::json(200, [
            "message" => ($decision["already_absent"] ?? false)
                ? "Signalement traite car le contenu est deja absent"
                : "Contenu retire et proprietaire notifie",
            "decision" => $decision,
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

    private function decisionError(DomainException $exception): never
    {
        $notFound = in_array($exception->getMessage(), [
            "Signalement introuvable",
            "Dossier de moderation introuvable",
        ], true);

        Response::error(
            $exception->getMessage(),
            $notFound ? 404 : 409,
            $notFound ? "moderation_target_not_found" : "moderation_decision_conflict",
        );
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
