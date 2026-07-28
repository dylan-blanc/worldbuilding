<?php

declare(strict_types=1);

/**
 * Exercises the page visibility matrix without a database or HTTP mutation.
 * Run this after PageReadAccess changes to protect JSON and MinIO reads equally.
 */
require_once __DIR__ . "/../core/PageReadAccess.php";

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$page = [
    "owner_user_id" => 12,
    "page_status" => "public",
];

$expect(PageReadAccess::allows($page, null, false), "Public pages must allow visitors");
$expect(PageReadAccess::allows($page, 99, false), "Public pages must allow authenticated users");

$page["page_status"] = "private";
$expect(PageReadAccess::allows($page, 12, false), "Private pages must allow their owner");
$expect(!PageReadAccess::allows($page, null, false), "Private pages must reject visitors");
$expect(!PageReadAccess::allows($page, 99, true), "Private pages must reject non-owner admins");

$page["page_status"] = "banned";
$expect(!PageReadAccess::allows($page, 12, false), "Banned pages must reject their owner");
$expect(PageReadAccess::allows($page, 99, true), "Banned pages must allow admins");

echo "Page read access tests passed\n";
