<?php

declare(strict_types=1);

/**
 * Applies durable administrator decisions to reports, current CMS documents and page presentation images.
 * AdminModerationController calls this model from POST moderation action endpoints; one SQL transaction locks
 * moderation -> pages -> current page_revision rows, updates report/case states, creates the owner notification,
 * and queues MinIO deletion. After commit, it attempts each deletion immediately; failed attempts remain
 * pending for StorageDeletionProcessor, which provides the daily retry path without blocking the SQL decision.
 */
final class ModerationDecision
{
    private const MEDIA_TYPES = ["image", "banner", "gallery", "video"];
    private const IMMEDIATE_DELETE_TIMEOUT_SECONDS = 3.0;
    private StorageDeletionQueue $deletions;

    public function __construct(private PDO $pdo)
    {
        $this->deletions = new StorageDeletionQueue($pdo);
    }

    public function dismissReport(int $reportId, int $adminUserId): array
    {
        return $this->transaction(function () use ($reportId, $adminUserId): array {
            $report = $this->lockReport($reportId);
            $this->requirePendingReport($report);
            $this->setReportStatus($reportId, "dismissed", $adminUserId);
            $case = $this->refreshCaseStatus((int) $report["moderation_case_id"], $adminUserId);

            return ["report_id" => $reportId, "case" => $case];
        });
    }

    public function dismissCase(int $caseId, int $adminUserId): array
    {
        return $this->transaction(function () use ($caseId, $adminUserId): array {
            $case = $this->lockCase($caseId);

            if ((string) $case["moderation_status"] !== "pending") {
                throw new DomainException("Ce dossier a deja ete traite");
            }

            $statement = $this->pdo->prepare(
                "UPDATE moderation
                SET moderation_status = \"dismissed\",
                    reviewed_by_user_id = :reviewed_by_user_id,
                    reviewed_at = CURRENT_TIMESTAMP
                WHERE moderation_case_id = :moderation_case_id
                    AND moderation_status = \"pending\""
            );
            $statement->execute([
                ":reviewed_by_user_id" => $adminUserId,
                ":moderation_case_id" => $caseId,
            ]);
            $case = $this->refreshCaseStatus($caseId, $adminUserId);

            return ["case" => $case];
        });
    }

    public function removeReportedContent(int $reportId, int $adminUserId): array
    {
        $decision = $this->transaction(function () use ($reportId, $adminUserId): array {
            $report = $this->lockReport($reportId);
            $this->requirePendingReport($report);
            $contentType = (string) $report["reported_content_type"];
            $moderation = $contentType === "page_content"
                ? $this->moderateCmsBlock($report)
                : $this->moderatePagePicture($report);

            if (!$moderation["found"]) {
                $this->reviewTargetReports($report, $adminUserId);
                $case = $this->refreshCaseStatus((int) $report["moderation_case_id"], $adminUserId);

                return [
                    "report_id" => $reportId,
                    "notification_id" => null,
                    "queued_media_count" => 0,
                    "queued_object_keys" => [],
                    "already_absent" => true,
                    "case" => $case,
                ];
            }

            $objectKeys = $moderation["object_keys"];
            $filters = $this->targetFilterNames($report);

            $this->reviewTargetReports($report, $adminUserId);
            $notificationId = $this->createNotification($report, $filters);
            $queuedMediaCount = 0;
            $queuedObjectKeys = [];

            foreach (array_values(array_unique($objectKeys)) as $objectKey) {
                if (!MediaUploadValidator::isOwnedObjectKey(
                    $objectKey,
                    (int) $report["owner_user_id"],
                    (int) $report["reported_page_id"],
                )) {
                    continue;
                }

                $this->queueStorageDeletion((int) $report["reported_page_id"], $objectKey);
                $queuedMediaCount++;
                $queuedObjectKeys[] = $objectKey;
            }

            $case = $this->refreshCaseStatus((int) $report["moderation_case_id"], $adminUserId);

            return [
                "report_id" => $reportId,
                "notification_id" => $notificationId,
                "queued_media_count" => $queuedMediaCount,
                "queued_object_keys" => $queuedObjectKeys,
                "already_absent" => false,
                "case" => $case,
            ];
        });
        $objectKeys = $decision["queued_object_keys"];
        unset($decision["queued_object_keys"]);
        $decision["immediate_deleted_count"] = $this->deleteQueuedMediaImmediately($objectKeys);

        return $decision;
    }

    private function deleteQueuedMediaImmediately(array $objectKeys): int
    {
        if ($objectKeys === []) {
            return 0;
        }

        try {
            $storage = new MinioStorage();
        } catch (Throwable) {
            error_log("Immediate MinIO deletion unavailable");
            return 0;
        }

        $deleted = 0;

        foreach ($objectKeys as $objectKey) {
            try {
                $storage->delete((string) $objectKey, self::IMMEDIATE_DELETE_TIMEOUT_SECONDS);
                $this->deletions->markDeleted((string) $objectKey);
                $deleted++;
            } catch (Throwable) {
                error_log("Immediate MinIO deletion failed");

                try {
                    $this->deletions->markImmediateFailure((string) $objectKey);
                } catch (Throwable) {
                    error_log("MinIO deletion fallback status update failed");
                }
            }
        }

        return $deleted;
    }

    private function moderateCmsBlock(array $report): array
    {
        $pageId = (int) $report["reported_page_id"];
        $blockId = (string) $report["reported_block_id"];
        $pageMutation = $this->moderatedDocument((string) $report["pagecontent"], $blockId);
        $found = $pageMutation["found"];
        $objectKeys = $pageMutation["object_keys"];

        if ($found) {
            $statement = $this->pdo->prepare(
                "UPDATE pages SET pagecontent = :pagecontent WHERE id = :page_id"
            );
            $statement->execute([
                ":pagecontent" => $pageMutation["json"],
                ":page_id" => $pageId,
            ]);
        }

        $revisions = $this->pdo->prepare(
            "SELECT id, pagecontent
            FROM page_revision
            WHERE page_id = :page_id AND is_current = TRUE
            FOR UPDATE"
        );
        $revisions->execute([":page_id" => $pageId]);

        foreach ($revisions->fetchAll(PDO::FETCH_ASSOC) as $revision) {
            $mutation = $this->moderatedDocument((string) $revision["pagecontent"], $blockId);
            $found = $found || $mutation["found"];
            $objectKeys = [...$objectKeys, ...$mutation["object_keys"]];

            if (!$mutation["found"]) {
                continue;
            }

            $update = $this->pdo->prepare(
                "UPDATE page_revision SET pagecontent = :pagecontent WHERE id = :id"
            );
            $update->execute([
                ":pagecontent" => $mutation["json"],
                ":id" => (int) $revision["id"],
            ]);
        }

        return [
            "found" => $found,
            "object_keys" => $objectKeys,
        ];
    }

    private function moderatedDocument(string $json, string $blockId): array
    {
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $document = is_array($document) ? $document : [];

        if (($document["schemaVersion"] ?? null) !== 1) {
            $document = $this->normalizedLegacyDocument($document);
        }

        $block = is_array($document) ? ($document["blocks"][$blockId] ?? null) : null;

        if (!is_array($block) || !is_array($block["props"] ?? null)) {
            return ["found" => false, "json" => $json, "object_keys" => []];
        }

        $objectKeys = [];
        $blockType = (string) ($block["type"] ?? "");

        if (in_array($blockType, self::MEDIA_TYPES, true)) {
            $objectKey = (string) ($block["props"]["objectKey"] ?? "");
            $objectKey !== "" && ($objectKeys[] = $objectKey);
            $block["props"]["objectKey"] = "";
            unset($block["props"]["mimeType"], $block["props"]["size"]);
        }

        $block["props"]["moderationRemoved"] = true;
        $document["blocks"][$blockId] = $block;

        return [
            "found" => true,
            "json" => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            "object_keys" => $objectKeys,
        ];
    }

    private function normalizedLegacyDocument(array $document): array
    {
        $normalized = CmsContentValidator::emptyDocument();
        $legacyBlocks = is_array($document["blocks"] ?? null) ? $document["blocks"] : [];

        foreach ($legacyBlocks as $index => $legacyBlock) {
            if (!is_array($legacyBlock) || preg_match("/^\d+$/", (string) $index) !== 1) {
                continue;
            }

            $legacyIndex = (int) $index;
            $id = "legacy-" . $legacyIndex;
            $legacyType = (string) ($legacyBlock["type"] ?? "paragraph");
            $text = is_scalar($legacyBlock["content"] ?? null)
                ? (string) $legacyBlock["content"]
                : "";
            $nodeType = $legacyType === "heading" ? "heading" : "paragraph";
            $normalized["blocks"][$id] = [
                "id" => $id,
                "type" => "text",
                "props" => [
                    "label" => $legacyType === "heading" ? "Titre" : "Texte",
                    "content" => [
                        "type" => "doc",
                        "content" => [[
                            "type" => $nodeType,
                            "attrs" => $nodeType === "heading" ? ["level" => 1] : [],
                            "content" => $text === "" ? [] : [[
                                "type" => "text",
                                "text" => $text,
                            ]],
                        ]],
                    ],
                    "objectKey" => null,
                ],
            ];
            $normalized["layouts"]["lg"][] = [
                "i" => $id,
                "parentId" => null,
                "x" => 0,
                "y" => $legacyIndex * 3,
                "w" => 12,
                "h" => $legacyType === "heading" ? 2 : 3,
            ];
        }

        return $normalized;
    }

    private function moderatePagePicture(array $report): array
    {
        $picture = (string) ($report["page_picture"] ?? "");

        if ($picture === "") {
            return ["found" => false, "object_keys" => []];
        }

        $statement = $this->pdo->prepare(
            "UPDATE pages SET page_picture = NULL WHERE id = :page_id"
        );
        $statement->execute([":page_id" => (int) $report["reported_page_id"]]);

        $objectKeys = MediaUploadValidator::isOwnedPagePictureKey(
            $picture,
            (int) $report["owner_user_id"],
            (int) $report["reported_page_id"],
        ) ? [$picture] : [];

        return ["found" => true, "object_keys" => $objectKeys];
    }

    private function targetFilterNames(array $report): array
    {
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT reported_filter_name
            FROM moderation
            WHERE moderation_case_id = :moderation_case_id
                AND reported_content_type = :reported_content_type
                AND reported_block_id = :reported_block_id
                AND moderation_status = \"pending\"
            ORDER BY reported_filter_name"
        );
        $statement->execute([
            ":moderation_case_id" => (int) $report["moderation_case_id"],
            ":reported_content_type" => (string) $report["reported_content_type"],
            ":reported_block_id" => (string) $report["reported_block_id"],
        ]);

        return array_values(array_filter(array_map("strval", $statement->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function reviewTargetReports(array $report, int $adminUserId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE moderation
            SET moderation_status = \"reviewed\",
                reviewed_by_user_id = :reviewed_by_user_id,
                reviewed_at = CURRENT_TIMESTAMP
            WHERE moderation_case_id = :moderation_case_id
                AND reported_content_type = :reported_content_type
                AND reported_block_id = :reported_block_id
                AND moderation_status = \"pending\""
        );
        $statement->execute([
            ":reviewed_by_user_id" => $adminUserId,
            ":moderation_case_id" => (int) $report["moderation_case_id"],
            ":reported_content_type" => (string) $report["reported_content_type"],
            ":reported_block_id" => (string) $report["reported_block_id"],
        ]);
    }

    private function createNotification(array $report, array $filters): int
    {
        $isPagePicture = (string) $report["reported_content_type"] === "page_display";
        $statement = $this->pdo->prepare(
            "INSERT INTO user_notifications (
                user_id, page_id, notification_type, title, message, details
            ) VALUES (
                :user_id, :page_id, :notification_type, :title, :message, :details
            )"
        );
        $statement->execute([
            ":user_id" => (int) $report["owner_user_id"],
            ":page_id" => (int) $report["reported_page_id"],
            ":notification_type" => $isPagePicture
                ? "moderation_page_picture_removed"
                : "moderation_content_removed",
            ":title" => "Décision de modération",
            ":message" => $isPagePicture
                ? "L'image de présentation de votre page a été retirée après examen."
                : "Un contenu de votre page a été retiré après examen.",
            ":details" => json_encode([
                "page_title" => (string) $report["page_title"],
                "content_type" => (string) $report["reported_content_type"],
                "block_type" => $report["reported_block_type"],
                "block_id" => (string) $report["reported_block_id"],
                "moderation_filters" => $filters,
                "content_snapshot" => $this->decodedSnapshot($report["reported_content_snapshot"] ?? null),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function queueStorageDeletion(int $pageId, string $objectKey): void
    {
        $this->deletions->enqueue($pageId, $objectKey);
    }

    private function lockReport(int $reportId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT moderation.*,
                pages.owner_user_id,
                pages.page_title,
                pages.page_picture,
                pages.pagecontent
            FROM moderation
            INNER JOIN pages ON pages.id = moderation.reported_page_id
            WHERE moderation.id = :id
            LIMIT 1
            FOR UPDATE"
        );
        $statement->execute([":id" => $reportId]);
        $report = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($report)) {
            throw new DomainException("Signalement introuvable");
        }

        return $report;
    }

    private function lockCase(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM moderation_cases WHERE id = :id LIMIT 1 FOR UPDATE"
        );
        $statement->execute([":id" => $caseId]);
        $case = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($case)) {
            throw new DomainException("Dossier de moderation introuvable");
        }

        return $case;
    }

    private function requirePendingReport(array $report): void
    {
        if ((string) $report["moderation_status"] !== "pending") {
            throw new DomainException("Ce signalement a deja ete traite");
        }
    }

    private function decodedSnapshot(mixed $snapshot): ?array
    {
        if (!is_string($snapshot) || trim($snapshot) === "") {
            return null;
        }

        $decoded = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    private function setReportStatus(int $reportId, string $status, int $adminUserId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE moderation
            SET moderation_status = :moderation_status,
                reviewed_by_user_id = :reviewed_by_user_id,
                reviewed_at = CURRENT_TIMESTAMP
            WHERE id = :id"
        );
        $statement->execute([
            ":moderation_status" => $status,
            ":reviewed_by_user_id" => $adminUserId,
            ":id" => $reportId,
        ]);
    }

    private function refreshCaseStatus(int $caseId, int $adminUserId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                SUM(moderation_status = \"pending\") AS pending_count,
                SUM(moderation_status = \"reviewed\") AS reviewed_count
            FROM moderation
            WHERE moderation_case_id = :moderation_case_id"
        );
        $statement->execute([":moderation_case_id" => $caseId]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $status = (int) ($counts["pending_count"] ?? 0) > 0
            ? "pending"
            : ((int) ($counts["reviewed_count"] ?? 0) > 0 ? "reviewed" : "dismissed");

        return $this->setCaseStatus($caseId, $status, $adminUserId);
    }

    private function setCaseStatus(int $caseId, string $status, int $adminUserId): array
    {
        $isPending = $status === "pending";
        $statement = $this->pdo->prepare(
            "UPDATE moderation_cases
            SET moderation_status = :moderation_status,
                reviewed_by_user_id = :reviewed_by_user_id,
                reviewed_at = :reviewed_at
            WHERE id = :id"
        );
        $statement->execute([
            ":moderation_status" => $status,
            ":reviewed_by_user_id" => $isPending ? null : $adminUserId,
            ":reviewed_at" => $isPending ? null : date("Y-m-d H:i:s"),
            ":id" => $caseId,
        ]);

        $case = $this->lockCase($caseId);

        return [
            "id" => (int) $case["id"],
            "moderation_status" => (string) $case["moderation_status"],
            "reviewed_by_user_id" => $case["reviewed_by_user_id"] === null
                ? null
                : (int) $case["reviewed_by_user_id"],
            "reviewed_at" => $case["reviewed_at"],
        ];
    }

    private function transaction(callable $operation): array
    {
        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }
    }
}
