<?php

declare(strict_types=1);

/**
 * Receives authenticated PageDisplay reports through POST /api/pages/{id}/reports.
 * The request follows session -> page/filter validation -> Moderation::create() -> SQL response.
 * The media URL is read from the public page row so clients cannot forge the reported snapshot.
 */
final class ModerationController
{
    private const MAX_MESSAGE_LENGTH = 2000;

    private Moderation $moderation;
    private Page $pages;
    private Filter $filters;

    public function __construct(PDO $pdo)
    {
        $this->moderation = new Moderation($pdo);
        $this->pages = new Page($pdo);
        $this->filters = new Filter($pdo);
    }

    public function createPageDisplayReport(int $pageId): void
    {
        Request::requireSameOrigin();
        $reporterUserId = $this->authenticatedUserId();
        $body = Request::body();
        $filterId = $this->positiveIntField($body, "reported_filter_content");
        $message = Request::field($body, ["reported_user_message"]);
        $page = $this->pages->findPublicReportTarget($pageId);
        $filter = $this->filters->findById($filterId);

        if ($page === null) {
            Response::error("Page introuvable", 404, "reported_page_not_found");
        }

        if ($filter === null || (string) $filter["filter_type"] !== "moderation") {
            Response::error("Motif de signalement invalide", 422, "invalid_report_filter");
        }

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            Response::error("Commentaire de signalement trop long", 422, "report_message_too_long");
        }

        try {
            $report = $this->moderation->create(
                $reporterUserId,
                $pageId,
                $filterId,
                $message === "" ? null : $message,
                isset($page["page_picture"]) && $page["page_picture"] !== ""
                    ? (string) $page["page_picture"]
                    : null
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                Response::error(
                    "Vous avez deja signale cette page",
                    409,
                    "page_already_reported"
                );
            }

            throw $exception;
        }

        Response::json(201, [
            "message" => "Signalement envoye",
            "report" => $report,
        ]);
    }

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Veuillez vous connecter", 401, "authentication_required");
        }

        return $userId;
    }

    private function positiveIntField(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        $validated = is_scalar($value)
            ? filter_var($value, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]])
            : false;

        if ($validated === false) {
            Response::error("Motif de signalement requis", 422, "report_filter_required");
        }

        return (int) $validated;
    }
}
