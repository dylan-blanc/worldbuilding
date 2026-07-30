<?php

declare(strict_types=1);

/**
 * Persists user reports created from PageDisplay and exposes their administration history.
 * POST /api/pages/{id}/reports inserts reporter, page, moderation filter and media snapshot data.
 * Admin list/history/status endpoints join moderation -> pages/users/filters for review responses.
 */
final class Moderation
{
    private const STATUSES = ["pending", "reviewed", "dismissed"];

    public function __construct(private PDO $pdo) {}

    public function create(
        int $reporterUserId,
        int $reportedPageId,
        int $reportedFilterContent,
        ?string $reportedUserMessage,
        ?string $reportedMediaUrl
    ): array {
        $statement = $this->pdo->prepare(
            "INSERT INTO moderation (
                reporter_user_id,
                reported_page_id,
                reported_filter_content,
                reported_user_message,
                reported_media_url,
                reported_content_type
            ) VALUES (
                :reporter_user_id,
                :reported_page_id,
                :reported_filter_content,
                :reported_user_message,
                :reported_media_url,
                :reported_content_type
            )"
        );
        $this->bindValues($statement, [
            ":reporter_user_id" => $reporterUserId,
            ":reported_page_id" => $reportedPageId,
            ":reported_filter_content" => $reportedFilterContent,
            ":reported_user_message" => $reportedUserMessage,
            ":reported_media_url" => $reportedMediaUrl,
            ":reported_content_type" => "page_display",
        ]);
        $statement->execute();

        $report = $this->findById((int) $this->pdo->lastInsertId());

        if ($report === null) {
            throw new RuntimeException("Signalement introuvable apres creation");
        }

        return $report;
    }

    public function findByStatus(string $status): array
    {
        $this->validateStatus($status);
        $statement = $this->pdo->prepare(
            $this->selectReportSql() . "
            WHERE moderation.moderation_status = :moderation_status
            ORDER BY report_count DESC, moderation.created_at DESC, moderation.id DESC"
        );
        $this->bindValues($statement, [
            ":moderation_status" => $status,
        ]);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findHistory(int $pageId, string $contentType): array
    {
        $statement = $this->pdo->prepare(
            $this->selectReportSql() . "
            WHERE moderation.reported_page_id = :reported_page_id
                AND moderation.reported_content_type = :reported_content_type
            ORDER BY moderation.created_at DESC, moderation.id DESC"
        );
        $this->bindValues($statement, [
            ":reported_page_id" => $pageId,
            ":reported_content_type" => $contentType,
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

    public function updateStatus(int $id, string $status, int $reviewerUserId): ?array
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

    private function selectReportSql(): string
    {
        return "SELECT moderation.id,
                moderation.reporter_user_id,
                reporter.username AS reporter_username,
                moderation.reported_page_id,
                pages.page_title,
                pages.page_status,
                pages.page_picture AS current_media_url,
                moderation.reported_filter_content,
                filters.filter_name AS reported_filter_name,
                moderation.reported_user_message,
                moderation.reported_media_url,
                moderation.reported_content_type,
                moderation.moderation_status,
                moderation.reviewed_by_user_id,
                reviewer.username AS reviewer_username,
                moderation.created_at,
                moderation.updated_at,
                moderation.reviewed_at,
                (
                    SELECT COUNT(*)
                    FROM moderation report_totals
                    WHERE report_totals.reported_page_id = moderation.reported_page_id
                        AND report_totals.reported_content_type = moderation.reported_content_type
                ) AS report_count
            FROM moderation
            INNER JOIN users reporter ON reporter.id = moderation.reporter_user_id
            INNER JOIN pages ON pages.id = moderation.reported_page_id
            INNER JOIN filters ON filters.id = moderation.reported_filter_content
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

        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }
}
