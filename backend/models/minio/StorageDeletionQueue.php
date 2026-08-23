<?php

declare(strict_types=1);

/**
 * Persists MinIO object keys before deletion so storage cleanup survives request or MinIO failures.
 * ModerationDecision commits the job before attempting immediate deletion, while StorageDeletionProcessor claims
 * pending retries; duplicate keys remain one idempotent job and no binary media is stored in SQL.
 */
final class StorageDeletionQueue
{
    public function __construct(private PDO $pdo) {}

    public function enqueue(int $pageId, string $objectKey): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO storage_deletion_outbox (page_id, object_key)
            VALUES (:page_id, :object_key)
            ON DUPLICATE KEY UPDATE
                deletion_status = IF(deletion_status = \"deleted\", deletion_status, \"pending\"),
                available_at = IF(deletion_status = \"deleted\", available_at, CURRENT_TIMESTAMP),
                attempts = IF(deletion_status = \"deleted\", attempts, 0),
                locked_at = IF(deletion_status = \"deleted\", locked_at, NULL),
                processed_at = IF(deletion_status = \"deleted\", processed_at, NULL),
                last_error_code = NULL"
        );
        $statement->execute([
            ":page_id" => $pageId,
            ":object_key" => $objectKey,
        ]);
    }

    public function markDeleted(string $objectKey): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE storage_deletion_outbox
            SET deletion_status = \"deleted\", processed_at = CURRENT_TIMESTAMP,
                locked_at = NULL, last_error_code = NULL
            WHERE object_key = :object_key"
        );
        $statement->execute([":object_key" => $objectKey]);
    }

    public function markImmediateFailure(string $objectKey): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE storage_deletion_outbox
            SET deletion_status = \"pending\", locked_at = NULL,
                last_error_code = \"immediate_delete_failed\"
            WHERE object_key = :object_key AND deletion_status = \"pending\""
        );
        $statement->execute([":object_key" => $objectKey]);
    }
}
