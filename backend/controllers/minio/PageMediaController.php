<?php

declare(strict_types=1);

/**
 * Handles secure CMS media uploads and authorized inline reads through MinIO.
 * POST /pages/{id}/media follows frontend multipart -> default detected-type normalization -> PHP validation/re-encoding -> MinIO putObject -> JSON objectKey.
 * POST/GET /pages/{id}/picture applies the same default normalization and stores or streams MinIO-only presentation images.
 * GET /pages/{id}/media applies PageReadAccess and the SQL user role before streaming MinIO bytes with nosniff headers.
 * GET /pages/{id}/owner-picture resolves the current non-anonymous owner picture through Page/User SQL, then MinIO.
 */
final class PageMediaController
{
    private const OUTPUT_MIMES = ["image/jpeg", "image/png", "image/webp", "image/avif", "image/gif", "video/mp4", "video/webm"];
    private const PROFILE_OUTPUT_MIMES = ["image/jpeg", "image/png", "image/webp", "image/avif", "image/gif"];

    private Page $pages;
    private MinioStorage $storage;
    private StorageDeletionQueue $deletions;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->pages = new Page($pdo);
        $this->users = new User($pdo);
        $this->deletions = new StorageDeletionQueue($pdo);

        try {
            $this->storage = new MinioStorage();
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::error("Stockage media indisponible", 503, "media_storage_unavailable");
        }
    }

    public function upload(int $pageId): void
    {
        Request::requireSameOrigin();
        $userId = $this->authenticatedUserId();
        $page = $this->pages->findOwnedById($pageId, $userId);

        if ($page === null) {
            Response::error("Page introuvable", 404, "page_not_found");
        }

        if ($page["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas recevoir de media", 403, "page_banned");
        }

        try {
            $media = MediaUploadValidator::validate(
                $_FILES["file"] ?? [],
                Request::field($_POST, ["media_type"]),
                !array_key_exists("normalize_image_type", $_POST)
                    || Request::field($_POST, ["normalize_image_type"]) === "1",
            );
            $objectKey = $userId . "/pages/" . $pageId . "/" . $media["category"] . "/" . bin2hex(random_bytes(16)) . "." . $media["extension"];
            $this->storage->upload($objectKey, $media);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 422, "invalid_media");
        } catch (Throwable $exception) {
            error_log("MinIO upload failed: " . $exception->getMessage());
            Response::error("Enregistrement du media impossible", 503, "media_storage_failed");
        } finally {
            isset($media) && ($media["cleanup"] ?? false) && @unlink((string) $media["path"]);
        }

        Response::json(201, [
            "message" => "Media valide et enregistre",
            "media" => [
                "objectKey" => $objectKey,
                "mediaType" => $media["category"] === "images" ? "image" : "video",
                "mimeType" => $media["mime_type"],
                "size" => $media["size"],
                "width" => $media["width"],
                "height" => $media["height"],
                "url" => "/api/pages/" . $pageId . "/media?key=" . rawurlencode($objectKey),
            ],
        ]);
    }

    public function show(int $pageId): void
    {
        $page = $this->pages->findContentById($pageId);

        if ($page === null) {
            Response::error("Media introuvable", 404, "media_not_found");
        }

        $viewerUserId = Session::userId();
        $viewerIsAdmin = $viewerUserId !== null && $this->users->isAdmin($viewerUserId);
        $ownerUserId = (int) $page["owner_user_id"];

        if (!PageReadAccess::allows($page, $viewerUserId, $viewerIsAdmin)) {
            Response::error("Media introuvable", 404, "media_not_found");
        }

        $key = isset($_GET["key"]) && is_scalar($_GET["key"]) ? (string) $_GET["key"] : "";

        if (!MediaUploadValidator::isOwnedObjectKey($key, $ownerUserId, $pageId)) {
            Response::error("Cle media invalide", 422, "invalid_media_key");
        }

        $range = $this->validatedRangeHeader();

        try {
            $result = $this->storage->read($key, $range);
        } catch (Throwable $exception) {
            error_log("MinIO read failed: " . $exception->getMessage());
            Response::error("Media introuvable", 404, "media_not_found");
        }

        $mime = (string) ($result["ContentType"] ?? "");

        if (!in_array($mime, self::OUTPUT_MIMES, true)) {
            Response::error("Type du media stocke invalide", 415, "invalid_stored_media");
        }

        http_response_code(isset($result["ContentRange"]) ? 206 : 200);
        header("Content-Type: " . $mime);
        header("Content-Disposition: inline");
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header("Cache-Control: private, no-store");
        header("Accept-Ranges: bytes");

        isset($result["ContentLength"]) && header("Content-Length: " . (int) $result["ContentLength"]);
        isset($result["ContentRange"]) && header("Content-Range: " . $result["ContentRange"]);
        $body = $result["Body"];

        while (!$body->eof()) {
            echo $body->read(1024 * 1024);
            flush();
        }

        exit;
    }

    public function uploadPagePicture(int $pageId): void
    {
        Request::requireSameOrigin();
        $userId = $this->authenticatedUserId();
        $page = $this->pages->findOwnedById($pageId, $userId);

        if ($page === null) {
            Response::error("Page introuvable", 404, "page_not_found");
        }

        if ($page["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas recevoir d'image", 403, "page_banned");
        }

        try {
            $media = MediaUploadValidator::validate(
                $_FILES["file"] ?? [],
                "image",
                !array_key_exists("normalize_image_type", $_POST)
                    || Request::field($_POST, ["normalize_image_type"]) === "1",
            );
            $objectKey = $userId . "/pages/" . $pageId . "/images/" . bin2hex(random_bytes(16)) . "." . $media["extension"];
            $this->storage->upload($objectKey, $media);
            $updatedPage = $this->pages->updatePicture($pageId, $userId, $objectKey);
            $previous = (string) ($page["page_picture"] ?? "");

            MediaUploadValidator::isOwnedPagePictureKey($previous, $userId, $pageId)
                && $this->deletions->enqueue($pageId, $previous);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 422, "invalid_page_picture");
        } catch (Throwable) {
            error_log("Page picture upload failed");
            Response::error("Enregistrement de l'image impossible", 503, "page_picture_upload_failed");
        } finally {
            isset($media) && ($media["cleanup"] ?? false) && @unlink((string) $media["path"]);
        }

        Response::json(201, [
            "message" => "Image de presentation enregistree",
            "page" => $updatedPage,
        ]);
    }

    public function pagePicture(int $pageId): void
    {
        $page = $this->pages->findContentById($pageId);

        if ($page === null) {
            Response::error("Image de presentation introuvable", 404, "page_picture_not_found");
        }

        $viewerUserId = Session::userId();
        $viewerIsAdmin = $viewerUserId !== null && $this->users->isAdmin($viewerUserId);

        if (!PageReadAccess::allows($page, $viewerUserId, $viewerIsAdmin)) {
            Response::error("Image de presentation introuvable", 404, "page_picture_not_found");
        }

        $key = (string) ($page["page_picture"] ?? "");

        if (!MediaUploadValidator::isOwnedPagePictureKey($key, (int) $page["owner_user_id"], $pageId)) {
            Response::error("Image de presentation introuvable", 404, "page_picture_not_found");
        }

        try {
            $result = $this->storage->read($key);
        } catch (Throwable) {
            error_log("Page picture read failed");
            Response::error("Image de presentation introuvable", 404, "page_picture_not_found");
        }

        $mime = (string) ($result["ContentType"] ?? "");

        if (!in_array($mime, self::PROFILE_OUTPUT_MIMES, true)) {
            Response::error("Format d'image de presentation invalide", 415, "invalid_page_picture");
        }

        http_response_code(200);
        header("Content-Type: " . $mime);
        header("Content-Disposition: inline");
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header("Cache-Control: private, no-store");
        isset($result["ContentLength"]) && header("Content-Length: " . (int) $result["ContentLength"]);
        $body = $result["Body"];

        while (!$body->eof()) {
            echo $body->read(1024 * 1024);
            flush();
        }

        exit;
    }

    public function ownerPicture(int $pageId): void
    {
        $page = $this->pages->findContentById($pageId);

        if ($page === null || (bool) $page["is_anonymous"]) {
            Response::error("Image de profil introuvable", 404, "profile_picture_not_found");
        }

        $viewerUserId = Session::userId();
        $viewerIsAdmin = $viewerUserId !== null && $this->users->isAdmin($viewerUserId);

        if (!PageReadAccess::allows($page, $viewerUserId, $viewerIsAdmin)) {
            Response::error("Image de profil introuvable", 404, "profile_picture_not_found");
        }

        $ownerUserId = (int) $page["owner_user_id"];
        $owner = $this->users->findById($ownerUserId);
        $key = is_array($owner) ? (string) ($owner["profil_picture"] ?? "") : "";

        if (!MediaUploadValidator::isOwnedProfilePictureKey($key, $ownerUserId)) {
            Response::error("Image de profil introuvable", 404, "profile_picture_not_found");
        }

        try {
            $result = $this->storage->read($key);
        } catch (Throwable $exception) {
            error_log("Page owner picture read failed: " . $exception->getMessage());
            Response::error("Image de profil introuvable", 404, "profile_picture_not_found");
        }

        $mime = (string) ($result["ContentType"] ?? "");

        if (!in_array($mime, self::PROFILE_OUTPUT_MIMES, true)) {
            Response::error("Format d'image de profil invalide", 415, "invalid_profile_picture");
        }

        http_response_code(200);
        header("Content-Type: " . $mime);
        header("Content-Disposition: inline");
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header("Cache-Control: private, no-store");
        isset($result["ContentLength"]) && header("Content-Length: " . (int) $result["ContentLength"]);
        $body = $result["Body"];

        while (!$body->eof()) {
            echo $body->read(1024 * 1024);
            flush();
        }

        exit;
    }

    private function validatedRangeHeader(): ?string
    {
        $range = $_SERVER["HTTP_RANGE"] ?? "";

        if ($range === "") {
            return null;
        }

        if (!preg_match("/^bytes=\\d+-\\d*$/", $range)) {
            Response::error("Plage media invalide", 416, "invalid_media_range");
        }

        return $range;
    }

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Non authentifie", 401, "authentication_required");
        }

        Session::renewForMutation();

        return $userId;
    }
}
