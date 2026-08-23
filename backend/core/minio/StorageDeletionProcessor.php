<?php

declare(strict_types=1);

/**
 * Drains durable MinIO-deletion jobs from a backend-only CLI process.
 * backend/scripts/minio/process-storage-deletions.php invokes processAll() from host cron at 08:00 Europe/Paris;
 * each object key is claimed transactionally, deleted outside SQL locks and retried the next day on failure.
 */
final class StorageDeletionProcessor
{
    private const MAX_ATTEMPTS = 5;
    private const STALE_LOCK_MINUTES = 60;

    public function __construct(
        private PDO $pdo,
        private MinioStorage $storage,
    ) {}

    public static function fromEnvironment(PDO $pdo): self
    {
        return new self($pdo, new MinioStorage());
    }

    public function processAll(): array
    {
        $this->releaseStaleLocks();
        $deleted = 0;
        $failed = 0;

        while (($job = $this->claimJob()) !== null) {
            try {
                $this->storage->delete((string) $job["object_key"]);
                $this->completeJob((int) $job["id"]);
                $deleted++;
            } catch (Throwable) {
                $this->failJob((int) $job["id"], (int) $job["attempts"]);
                $failed++;
            }
        }

        return [
            "storage_deleted" => $deleted,
            "jobs_failed" => $failed,
        ];
    }

    private function claimJob(): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->query(
                "SELECT id, object_key, attempts
                FROM storage_deletion_outbox
                WHERE deletion_status = \"pending\" AND available_at <= CURRENT_TIMESTAMP
                ORDER BY id
                LIMIT 1
                FOR UPDATE SKIP LOCKED"
            );
            $job = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE storage_deletion_outbox
                SET deletion_status = \"processing\", attempts = attempts + 1, locked_at = CURRENT_TIMESTAMP
                WHERE id = :id"
            );
            $update->execute([":id" => (int) $job["id"]]);
            $this->pdo->commit();
            $job["attempts"] = (int) $job["attempts"] + 1;

            return $job;
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function completeJob(int $jobId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE storage_deletion_outbox
            SET deletion_status = \"deleted\", processed_at = CURRENT_TIMESTAMP,
                locked_at = NULL, last_error_code = NULL
            WHERE id = :id AND deletion_status = \"processing\""
        );
        $statement->execute([":id" => $jobId]);
    }

    private function failJob(int $jobId, int $attempts): void
    {
        $status = $attempts >= self::MAX_ATTEMPTS ? "failed" : "pending";
        $statement = $this->pdo->prepare(
            "UPDATE storage_deletion_outbox
            SET deletion_status = :status,
                available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY),
                locked_at = NULL,
                last_error_code = \"storage_delete_failed\"
            WHERE id = :id AND deletion_status = \"processing\""
        );
        $statement->execute([
            ":status" => $status,
            ":id" => $jobId,
        ]);
    }

    private function releaseStaleLocks(): void
    {
        $minutes = self::STALE_LOCK_MINUTES;
        $this->pdo->exec(
            "UPDATE storage_deletion_outbox
            SET deletion_status = \"pending\", locked_at = NULL
            WHERE deletion_status = \"processing\"
                AND locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL " . $minutes . " MINUTE)"
        );
    }
}
