<?php

declare(strict_types=1);

/**
 * Runs the backend-only MinIO deletion queue from host cron at 08:00 Europe/Paris.
 * The Composer bootstrap provides PDO and server-only MinIO credentials; output contains aggregate counts only.
 * Suggested invocation: docker compose -f docker-compose.prod.yml exec -T backend php scripts/minio/process-storage-deletions.php
 */
require_once __DIR__ . "/../../vendor/autoload.php";

if (PHP_SAPI !== "cli") {
    fwrite(STDERR, "Ce script doit etre execute en ligne de commande\n");
    exit(1);
}

try {
    $result = StorageDeletionProcessor::fromEnvironment($pdo)->processAll();
} catch (Throwable) {
    fwrite(STDERR, "StorageDeletionProcessor FAILED\n");
    exit(1);
}

echo sprintf(
    "StorageDeletionProcessor: storage=%d failed=%d\n",
    $result["storage_deleted"],
    $result["jobs_failed"],
);
