<?php

declare(strict_types=1);

/**
 * Audits stored CMS revisions without modifying SQL data or printing private page content.
 * It compares blocks with every layout reference to diagnose PUT /pages/{id}/draft validation failures.
 * Data follows MySQL page_revision -> this read-only script -> compact integrity summaries for developers.
 */

require_once __DIR__ . "/../config/env.php";
require_once __DIR__ . "/../config/database.php";

/** @var PDO $pdo */
$statement = $pdo->query(
    "SELECT id, page_id, revision_number, revision_status, updated_at, pagecontent
     FROM page_revision
     ORDER BY updated_at DESC
     LIMIT 25"
);
$revisions = $statement->fetchAll();

foreach ($revisions as $revision) {
    $document = json_decode((string) $revision["pagecontent"], true);
    $blocks = is_array($document["blocks"] ?? null) ? $document["blocks"] : [];
    $layouts = is_array($document["layouts"] ?? null) ? $document["layouts"] : [];
    $errors = [];

    foreach (["lg", "md", "sm", "xs"] as $breakpoint) {
        $seen = [];
        $items = is_array($layouts[$breakpoint] ?? null) ? $layouts[$breakpoint] : [];

        foreach ($items as $index => $item) {
            $blockId = is_array($item) ? ($item["i"] ?? null) : null;

            if (!is_string($blockId) || !array_key_exists($blockId, $blocks)) {
                $errors[] = $breakpoint . "[" . $index . "]:unknown";
                continue;
            }

            if (isset($seen[$blockId])) {
                $errors[] = $breakpoint . "[" . $index . "]:duplicate";
            }

            $seen[$blockId] = true;
        }
    }

    echo implode(" ", [
        "revision=" . $revision["id"],
        "page=" . $revision["page_id"],
        "number=" . $revision["revision_number"],
        "status=" . $revision["revision_status"],
        "updated=" . $revision["updated_at"],
        "blocks=" . count($blocks),
        "layoutLg=" . count($layouts["lg"] ?? []),
        "integrity=" . ($errors === [] ? "ok" : implode(",", $errors)),
    ]) . "\n";
}
