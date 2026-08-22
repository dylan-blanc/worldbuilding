<?php

declare(strict_types=1);

/**
 * Exercises the page visibility matrix without a database or HTTP mutation.
 * Run this after PageReadAccess changes to protect JSON and MinIO reads equally.
 */
require_once __DIR__ . "/../../vendor/autoload.php";

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$page = [
    "owner_user_id" => 12,
    "page_status" => "public",
    "is_anonymous" => false,
];

$expect(PageReadAccess::allows($page, null, false), "Public pages must allow visitors");
$expect(PageReadAccess::allows($page, 99, false), "Public pages must allow authenticated users");
$expect(PageReadAccess::statusBadge($page, null, false) === null, "Visitors must not see a public badge");
$expect(PageReadAccess::statusBadge($page, 99, true) === null, "Admins must not see a standard public badge");
$expect(PageReadAccess::statusBadge($page, 12, false) === "public", "Owners must see their public badge");

$page["is_anonymous"] = true;
$expect(PageReadAccess::statusBadge($page, null, false) === null, "Visitors must not see an anonymous badge");
$expect(PageReadAccess::statusBadge($page, 12, false) === "anonymous", "Owners must see their anonymous badge");
$expect(PageReadAccess::statusBadge($page, 99, true) === "anonymous", "Admins must see anonymous pages");

$page["is_anonymous"] = false;
$page["page_status"] = "private";
$expect(PageReadAccess::allows($page, 12, false), "Private pages must allow their owner");
$expect(!PageReadAccess::allows($page, null, false), "Private pages must reject visitors");
$expect(PageReadAccess::allows($page, 99, true), "Private pages must allow admins for moderation");
$expect(PageReadAccess::statusBadge($page, 12, false) === "private", "Owners must see their private badge");
$expect(PageReadAccess::statusBadge($page, 99, true) === "private", "Admins must see private pages");

$page["page_status"] = "banned";
$page["is_anonymous"] = true;
$expect(!PageReadAccess::allows($page, 12, false), "Banned pages must reject their owner");
$expect(PageReadAccess::allows($page, 99, true), "Banned pages must allow admins");
$expect(PageReadAccess::statusBadge($page, 99, true) === "banned", "Banned status must take priority over anonymity");

echo "Page read access tests passed\n";

