<?php

declare(strict_types=1);

/**
 * Receives authenticated PageDisplay and CmsResultBlock reports through POST /api/pages/{id}/reports.
 * Requests follow session -> page access -> filter/block validation -> Moderation::create() -> SQL.
 * Page JSON and media keys are read server-side so clients cannot forge the reported snapshot.
 */
final class ModerationController
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const REPORTABLE_BLOCK_TYPES = ["text", "image", "banner", "gallery", "video"];

    private Moderation $moderation;
    private Page $pages;
    private Filter $filters;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->moderation = new Moderation($pdo);
        $this->pages = new Page($pdo);
        $this->filters = new Filter($pdo);
        $this->users = new User($pdo);
    }

    public function createReport(int $pageId): void
    {
        Request::requireSameOrigin();
        $reporterUserId = $this->authenticatedUserId();
        $body = Request::body();
        $filterId = $this->positiveIntField($body, "reported_filter_content");
        $message = Request::field($body, ["reported_user_message"]);
        $contentType = Request::field($body, ["reported_content_type"]) ?: "page_display";
        $blockId = Request::field($body, ["reported_block_id"]);
        $filter = $this->filters->findById($filterId);

        if ($filter === null || (string) $filter["filter_type"] !== "moderation") {
            Response::error("Motif de signalement invalide", 422, "invalid_report_filter");
        }

        if ($this->filters->findChildrenById($filterId) !== []) {
            Response::error(
                "Veuillez choisir un sous-motif de signalement",
                422,
                "report_child_filter_required"
            );
        }

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            Response::error("Commentaire de signalement trop long", 422, "report_message_too_long");
        }

        [$page, $reportedBlock, $reportedMediaUrl] = $contentType === "page_content"
            ? $this->pageContentTarget($pageId, $blockId, $reporterUserId)
            : $this->pageDisplayTarget($pageId, $contentType);
        $reportedBlockType = is_array($reportedBlock) ? (string) $reportedBlock["type"] : null;
        $reportedContentSnapshot = is_array($reportedBlock)
            ? json_encode($reportedBlock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;
        $reportedPageContent = json_encode(
            $page["pagecontent"],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        try {
            $report = $this->moderation->create(
                $reporterUserId,
                $pageId,
                $filterId,
                (string) $filter["filter_name"],
                $message === "" ? null : $message,
                $reportedMediaUrl,
                $contentType,
                $contentType === "page_content" ? $blockId : "",
                $reportedBlockType,
                $reportedContentSnapshot,
                $reportedPageContent
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                Response::error(
                    $contentType === "page_content"
                        ? "Vous avez deja signale ce bloc"
                        : "Vous avez deja signale cette page",
                    409,
                    $contentType === "page_content" ? "block_already_reported" : "page_already_reported"
                );
            }

            throw $exception;
        }

        Response::json(201, [
            "message" => "Signalement envoye",
            "report" => $report,
        ]);
    }

    private function pageDisplayTarget(int $pageId, string $contentType): array
    {
        if ($contentType !== "page_display") {
            Response::error("Type de contenu signale invalide", 422, "invalid_report_content_type");
        }

        $page = $this->pages->findPublicReportTarget($pageId);

        if ($page === null) {
            Response::error("Page introuvable", 404, "reported_page_not_found");
        }

        $mediaUrl = isset($page["page_picture"]) && $page["page_picture"] !== ""
            ? (string) $page["page_picture"]
            : null;

        return [$page, null, $mediaUrl];
    }

    private function pageContentTarget(int $pageId, string $blockId, int $reporterUserId): array
    {
        if ($blockId === "" || strlen($blockId) > 255) {
            Response::error("Bloc signale requis", 422, "reported_block_required");
        }

        $page = $this->pages->findContentById($pageId);
        $isAdmin = $this->users->isAdmin($reporterUserId);

        if ($page === null || !PageReadAccess::allows($page, $reporterUserId, $isAdmin)) {
            Response::error("Page introuvable", 404, "reported_page_not_found");
        }

        $block = $this->findReportedBlock($page["pagecontent"], $blockId);

        if ($block === null || !in_array((string) ($block["type"] ?? ""), self::REPORTABLE_BLOCK_TYPES, true)) {
            Response::error("Bloc signale invalide", 422, "invalid_reported_block");
        }

        $objectKey = isset($block["props"]["objectKey"]) && is_scalar($block["props"]["objectKey"])
            ? trim((string) $block["props"]["objectKey"])
            : "";
        $mediaUrl = $objectKey === ""
            ? null
            : "/api/pages/" . $pageId . "/media?key=" . rawurlencode($objectKey);

        return [$page, $block, $mediaUrl];
    }

    private function findReportedBlock(mixed $pageContent, string $blockId): ?array
    {
        $document = json_decode(
            json_encode($pageContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $blocks = is_array($document["blocks"] ?? null) ? $document["blocks"] : [];

        if (($document["schemaVersion"] ?? null) === 1) {
            $block = $blocks[$blockId] ?? null;

            return is_array($block) ? $block : null;
        }

        if (preg_match("/^legacy-(\\d+)$/", $blockId, $matches) !== 1) {
            return null;
        }

        $legacyBlock = $blocks[(int) $matches[1]] ?? null;

        if (!is_array($legacyBlock)) {
            return null;
        }

        $legacyType = (string) ($legacyBlock["type"] ?? "paragraph");
        $text = is_scalar($legacyBlock["content"] ?? null) ? (string) $legacyBlock["content"] : "";

        return [
            "id" => $blockId,
            "type" => "text",
            "props" => [
                "label" => $legacyType === "heading" ? "Titre" : "Texte",
                "content" => [
                    "type" => "doc",
                    "content" => [[
                        "type" => $legacyType === "heading" ? "heading" : "paragraph",
                        "attrs" => $legacyType === "heading" ? ["level" => 1] : [],
                        "content" => $text === "" ? [] : [[
                            "type" => "text",
                            "text" => $text,
                        ]],
                    ]],
                ],
            ],
        ];
    }

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Veuillez vous connecter", 401, "authentication_required");
        }

        Session::renewForMutation();

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
