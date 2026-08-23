<?php

declare(strict_types=1);

/**
 * Persists one moderation case per page and the individual reports attached to it.
 * POST /api/pages/{id}/reports follows controller -> create() -> moderation_cases/moderation SQL.
 * Admin list, owner grouping, context and PATCH endpoints read cases, child decisions and page data here.
 */
final class Moderation
{
    private const STATUSES = ["pending", "reviewed", "dismissed"];

    public function __construct(private PDO $pdo) {}

    public function create(
        int $reporterUserId,
        int $reportedPageId,
        int $reportedFilterContent,
        string $reportedFilterName,
        ?string $reportedUserMessage,
        ?string $reportedMediaUrl,
        string $reportedContentType,
        string $reportedBlockId,
        ?string $reportedBlockType,
        ?string $reportedContentSnapshot,
        string $reportedPageContent
    ): array {
        $this->pdo->beginTransaction();

        try {
            $caseId = $this->findOrCreateCase($reportedPageId, $reportedPageContent);
            $statement = $this->pdo->prepare(
                "INSERT INTO moderation (
                    moderation_case_id,
                    reporter_user_id,
                    reported_page_id,
                    reported_filter_content,
                    reported_filter_name,
                    reported_user_message,
                    reported_media_url,
                    reported_content_type,
                    reported_block_id,
                    reported_block_type,
                    reported_content_snapshot
                ) VALUES (
                    :moderation_case_id,
                    :reporter_user_id,
                    :reported_page_id,
                    :reported_filter_content,
                    :reported_filter_name,
                    :reported_user_message,
                    :reported_media_url,
                    :reported_content_type,
                    :reported_block_id,
                    :reported_block_type,
                    :reported_content_snapshot
                )"
            );
            $this->bindValues($statement, [
                ":moderation_case_id" => $caseId,
                ":reporter_user_id" => $reporterUserId,
                ":reported_page_id" => $reportedPageId,
                ":reported_filter_content" => $reportedFilterContent,
                ":reported_filter_name" => $reportedFilterName,
                ":reported_user_message" => $reportedUserMessage,
                ":reported_media_url" => $reportedMediaUrl,
                ":reported_content_type" => $reportedContentType,
                ":reported_block_id" => $reportedBlockId,
                ":reported_block_type" => $reportedBlockType,
                ":reported_content_snapshot" => $reportedContentSnapshot,
            ]);
            $statement->execute();
            $reportId = (int) $this->pdo->lastInsertId();

            $this->refreshCaseSnapshot($caseId, $reportedPageContent);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $report = $this->findById($reportId);

        if ($report === null) {
            throw new RuntimeException("Signalement introuvable apres creation");
        }

        return $report;
    }

    public function findCasesByStatus(string $status): array
    {
        $this->validateStatus($status);
        $statement = $this->pdo->prepare(
            "SELECT moderation_cases.id,
                moderation_cases.reported_page_id,
                moderation_cases.moderation_status,
                moderation_cases.reviewed_by_user_id,
                reviewer.username AS reviewer_username,
                moderation_cases.created_at,
                moderation_cases.updated_at,
                moderation_cases.reviewed_at,
                pages.owner_user_id AS page_owner_user_id,
                owner.username AS page_owner_username,
                owner.profil_picture AS page_owner_picture,
                pages.page_title,
                pages.page_status,
                pages.is_anonymous AS page_is_anonymous,
                pages.number_of_likes,
                pages.number_of_view,
                pages.number_of_followers,
                pages.page_description,
                pages.page_picture,
                pages.created_at AS page_created_at,
                pages.updated_at AS page_updated_at,
                COUNT(moderation.id) AS report_count,
                SUM(moderation.moderation_status = 'pending') AS pending_report_count,
                SUM(moderation.moderation_status = 'reviewed') AS reviewed_report_count,
                SUM(moderation.moderation_status = 'dismissed') AS dismissed_report_count
            FROM moderation_cases
            INNER JOIN pages ON pages.id = moderation_cases.reported_page_id
            INNER JOIN users owner ON owner.id = pages.owner_user_id
            INNER JOIN moderation ON moderation.moderation_case_id = moderation_cases.id
            LEFT JOIN users reviewer ON reviewer.id = moderation_cases.reviewed_by_user_id
            WHERE moderation_cases.moderation_status = :moderation_status
            GROUP BY moderation_cases.id,
                moderation_cases.reported_page_id,
                moderation_cases.moderation_status,
                moderation_cases.reviewed_by_user_id,
                reviewer.username,
                moderation_cases.created_at,
                moderation_cases.updated_at,
                moderation_cases.reviewed_at,
                pages.owner_user_id,
                owner.username,
                owner.profil_picture,
                pages.page_title,
                pages.page_status,
                pages.is_anonymous,
                pages.number_of_likes,
                pages.number_of_view,
                pages.number_of_followers,
                pages.page_description,
                pages.page_picture,
                pages.created_at,
                pages.updated_at
            ORDER BY report_count DESC, moderation_cases.updated_at DESC, moderation_cases.id DESC"
        );
        $this->bindValues($statement, [
            ":moderation_status" => $status,
        ]);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findReportsByPageOwner(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT owner.id AS owner_user_id,
                owner.username AS owner_username,
                owner.profil_picture AS owner_picture,
                moderation_cases.id AS moderation_case_id,
                moderation_cases.moderation_status AS case_status,
                moderation_cases.created_at AS case_created_at,
                moderation_cases.updated_at AS case_updated_at,
                pages.id AS page_id,
                pages.page_title,
                pages.page_status,
                moderation.id AS report_id,
                moderation.moderation_status AS report_status,
                moderation.reported_content_type,
                moderation.reported_block_id,
                moderation.reported_block_type,
                moderation.reported_user_message,
                moderation.created_at AS report_created_at,
                moderation.reported_filter_name,
                reporter.id AS reporter_user_id,
                reporter.username AS reporter_username
            FROM moderation_cases
            INNER JOIN pages ON pages.id = moderation_cases.reported_page_id
            INNER JOIN users owner ON owner.id = pages.owner_user_id
            INNER JOIN moderation ON moderation.moderation_case_id = moderation_cases.id
            INNER JOIN users reporter ON reporter.id = moderation.reporter_user_id
            ORDER BY owner.username, owner.id, moderation_cases.updated_at DESC,
                moderation.created_at DESC, moderation.id DESC"
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findReportsByCaseId(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            $this->selectReportSql() . "
            WHERE moderation.moderation_case_id = :moderation_case_id
            ORDER BY moderation.created_at DESC, moderation.id DESC"
        );
        $this->bindValues($statement, [
            ":moderation_case_id" => $caseId,
        ]);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            $this->selectReportSql() . "
            WHERE moderation.id = :id
            LIMIT 1"
        );
        $this->bindValues($statement, [
            ":id" => $id,
        ]);
        $statement->execute();
        $report = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($report) ? $report : null;
    }

    public function findCaseById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT moderation_cases.*
            FROM moderation_cases
            WHERE moderation_cases.id = :id
            LIMIT 1"
        );
        $this->bindValues($statement, [
            ":id" => $id,
        ]);
        $statement->execute();
        $case = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($case) ? $case : null;
    }

    public function updateReportStatus(int $id, string $status, int $reviewerUserId): ?array
    {
        $this->validateStatus($status);
        $isPending = $status === "pending";
        $statement = $this->pdo->prepare(
            "UPDATE moderation
            SET moderation_status = :moderation_status,
                reviewed_by_user_id = :reviewed_by_user_id,
                reviewed_at = :reviewed_at
            WHERE id = :id"
        );
        $this->bindValues($statement, [
            ":id" => $id,
            ":moderation_status" => $status,
            ":reviewed_by_user_id" => $isPending ? null : $reviewerUserId,
            ":reviewed_at" => $isPending ? null : date("Y-m-d H:i:s"),
        ]);
        $statement->execute();

        return $this->findById($id);
    }

    public function updateCaseStatus(int $id, string $status, int $reviewerUserId): ?array
    {
        $this->validateStatus($status);
        $isPending = $status === "pending";
        $statement = $this->pdo->prepare(
            "UPDATE moderation_cases
            SET moderation_status = :moderation_status,
                reviewed_by_user_id = :reviewed_by_user_id,
                reviewed_at = :reviewed_at
            WHERE id = :id"
        );
        $this->bindValues($statement, [
            ":id" => $id,
            ":moderation_status" => $status,
            ":reviewed_by_user_id" => $isPending ? null : $reviewerUserId,
            ":reviewed_at" => $isPending ? null : date("Y-m-d H:i:s"),
        ]);
        $statement->execute();

        return $this->findCaseById($id);
    }

    public function findPageContext(int $pageId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT pages.id,
                pages.owner_user_id,
                owner.username AS owner_username,
                owner.profil_picture AS owner_picture,
                pages.page_title,
                pages.page_status,
                pages.is_anonymous,
                pages.number_of_likes,
                pages.number_of_view,
                pages.number_of_followers,
                pages.page_description,
                pages.page_picture,
                pages.pagecontent,
                pages.created_at,
                pages.updated_at
            FROM pages
            INNER JOIN users owner ON owner.id = pages.owner_user_id
            WHERE pages.id = :page_id
            LIMIT 1"
        );
        $this->bindValues($statement, [
            ":page_id" => $pageId,
        ]);
        $statement->execute();
        $page = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($page) ? $page : null;
    }

    public function findPageRevisionHistory(int $pageId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT page_revision.id,
                page_revision.revision_number,
                page_revision.revision_status,
                page_revision.is_current,
                page_revision.pagecontent,
                page_revision.created_at,
                page_revision.updated_at,
                page_revision.published_at,
                creator.username AS created_by_username
            FROM page_revision
            LEFT JOIN users creator ON creator.id = page_revision.created_by_user_id
            WHERE page_revision.page_id = :page_id
            ORDER BY page_revision.revision_number DESC, page_revision.id DESC"
        );
        $this->bindValues($statement, [
            ":page_id" => $pageId,
        ]);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findOrCreateCase(int $pageId, string $pageContent): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id
            FROM moderation_cases
            WHERE reported_page_id = :reported_page_id
            FOR UPDATE"
        );
        $this->bindValues($statement, [
            ":reported_page_id" => $pageId,
        ]);
        $statement->execute();
        $caseId = $statement->fetchColumn();

        if ($caseId !== false) {
            $reopen = $this->pdo->prepare(
                "UPDATE moderation_cases
                SET moderation_status = 'pending',
                    reviewed_by_user_id = NULL,
                    reviewed_at = NULL
                WHERE id = :id"
            );
            $this->bindValues($reopen, [
                ":id" => (int) $caseId,
            ]);
            $reopen->execute();

            return (int) $caseId;
        }

        $snapshot = $this->caseSnapshotJson($pageContent, []);
        $insert = $this->pdo->prepare(
            "INSERT INTO moderation_cases (reported_page_id, reported_page_snapshot)
            VALUES (:reported_page_id, :reported_page_snapshot)"
        );
        $this->bindValues($insert, [
            ":reported_page_id" => $pageId,
            ":reported_page_snapshot" => $snapshot,
        ]);
        $insert->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function refreshCaseSnapshot(int $caseId, string $pageContent): void
    {
        $reports = $this->findReportsByCaseId($caseId);
        $references = array_map(static fn (array $report): array => [
            "report_id" => (int) $report["id"],
            "content_type" => (string) $report["reported_content_type"],
            "block_id" => (string) $report["reported_block_id"],
            "block_type" => $report["reported_block_type"],
            "created_at" => (string) $report["created_at"],
        ], $reports);
        $statement = $this->pdo->prepare(
            "UPDATE moderation_cases
            SET reported_page_snapshot = :reported_page_snapshot
            WHERE id = :id"
        );
        $this->bindValues($statement, [
            ":id" => $caseId,
            ":reported_page_snapshot" => $this->caseSnapshotJson($pageContent, $references),
        ]);
        $statement->execute();
    }

    private function caseSnapshotJson(string $pageContent, array $reports): string
    {
        $decodedPageContent = json_decode($pageContent, true, 512, JSON_THROW_ON_ERROR);

        return json_encode([
            "pagecontent" => $decodedPageContent,
            "reports" => $reports,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function selectReportSql(): string
    {
        return "SELECT moderation.id,
                moderation.moderation_case_id,
                moderation.reporter_user_id,
                reporter.username AS reporter_username,
                moderation.reported_page_id,
                moderation.reported_filter_content,
                moderation.reported_filter_name,
                moderation.reported_user_message,
                moderation.reported_media_url,
                moderation.reported_content_type,
                moderation.reported_block_id,
                moderation.reported_block_type,
                moderation.reported_content_snapshot,
                moderation.moderation_status,
                moderation.reviewed_by_user_id,
                reviewer.username AS reviewer_username,
                moderation.created_at,
                moderation.updated_at,
                moderation.reviewed_at
            FROM moderation
            INNER JOIN users reporter ON reporter.id = moderation.reporter_user_id
            LEFT JOIN users reviewer ON reviewer.id = moderation.reviewed_by_user_id";
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Statut de moderation invalide");
        }
    }

    private function bindValues(PDOStatement $statement, array $values): void
    {
        foreach ($values as $key => $value) {
            $statement->bindValue($key, $value, $this->pdoParamType($value));
        }
    }

    private function pdoParamType(mixed $value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        return is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
    }
}
