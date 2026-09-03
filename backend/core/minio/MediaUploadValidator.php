<?php

declare(strict_types=1);

/**
 * Validates multipart CMS and profile media before controllers stream it to MinIO.
 * Media POST endpoints pass PHP's temporary upload through size, extension, Fileinfo, signature and image decoding checks.
 * Default-enabled image normalization accepts a mismatched safe extension, then uses the detected MIME for the MinIO key and GD output.
 * Images are re-encoded with GD to remove appended payloads; videos remain temporary files and are never executed locally.
 */
final class MediaUploadValidator
{
    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;
    private const VIDEO_MAX_BYTES = 250 * 1024 * 1024;
    private const IMAGE_MAX_PIXELS = 40_000_000;
    private const IMAGE_MAX_DIMENSION = 16_384;
    private const IMAGE_MIMES = [
        "image/jpeg" => ["extensions" => ["jpg", "jpeg"], "extension" => "jpg"],
        "image/png" => ["extensions" => ["png"], "extension" => "png"],
        "image/webp" => ["extensions" => ["webp"], "extension" => "webp"],
        "image/avif" => ["extensions" => ["avif"], "extension" => "avif"],
        "image/gif" => ["extensions" => ["gif"], "extension" => "gif"],
    ];
    private const VIDEO_MIMES = [
        "video/mp4" => ["extensions" => ["mp4"], "extension" => "mp4"],
        "application/mp4" => ["extensions" => ["mp4"], "extension" => "mp4", "mime" => "video/mp4"],
        "video/webm" => ["extensions" => ["webm"], "extension" => "webm"],
    ];
    private const DANGEROUS_NAME_PARTS = [
        "exe", "cmd", "bat", "com", "msi", "ps1", "sh", "scr", "jar", "php", "phtml", "phar", "js", "vbs",
    ];

    public static function validate(array $file, string $requestedType, bool $normalizeImageType = true): array
    {
        self::validateUploadEnvelope($file);

        if (!in_array($requestedType, ["image", "video"], true)) {
            throw new DomainException("Type de media requis: image ou video");
        }

        $temporaryPath = (string) $file["tmp_name"];
        $originalName = (string) $file["name"];
        $size = filesize($temporaryPath);

        if ($size === false || $size < 1) {
            throw new DomainException("Fichier vide ou illisible");
        }

        $maximum = $requestedType === "image" ? self::IMAGE_MAX_BYTES : self::VIDEO_MAX_BYTES;

        if ($size > $maximum) {
            throw new DomainException($requestedType === "image" ? "Image superieure a 10 Mo" : "Video superieure a 250 Mo");
        }

        self::rejectDangerousOriginalName($originalName);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($temporaryPath);
        $allowedMimes = $requestedType === "image" ? self::IMAGE_MIMES : self::VIDEO_MIMES;
        $configuration = is_string($detectedMime) ? ($allowedMimes[$detectedMime] ?? null) : null;

        if (!is_array($configuration)) {
            throw new DomainException("Le contenu reel du fichier ne correspond pas a un media autorise");
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $configuration["extensions"], true)
            && ($requestedType !== "image" || !$normalizeImageType)
        ) {
            throw new DomainException("L'extension du fichier ne correspond pas a son contenu");
        }

        $normalizedMime = $configuration["mime"] ?? $detectedMime;
        self::validateSignature($temporaryPath, $normalizedMime);

        if ($requestedType === "video") {
            return [
                "path" => $temporaryPath,
                "cleanup" => false,
                "category" => "videos",
                "extension" => $configuration["extension"],
                "mime_type" => $normalizedMime,
                "size" => $size,
                "width" => null,
                "height" => null,
            ];
        }

        return self::sanitizeImage($temporaryPath, $normalizedMime, $configuration["extension"]);
    }

    public static function isOwnedObjectKey(string $key, int $ownerUserId, int $pageId): bool
    {
        return preg_match(
            "#^" . preg_quote((string) $ownerUserId, "#") . "/pages/" . preg_quote((string) $pageId, "#") . "/(images|videos)/[a-f0-9]{32}\\.(jpg|png|webp|avif|gif|mp4|webm)$#",
            $key
        ) === 1;
    }

    public static function isOwnedPagePictureKey(string $key, int $ownerUserId, int $pageId): bool
    {
        return preg_match(
            "#^" . preg_quote((string) $ownerUserId, "#") . "/pages/" . preg_quote((string) $pageId, "#") . "/images/[a-f0-9]{32}\\.(jpg|png|webp|avif|gif)$#",
            $key
        ) === 1;
    }

    public static function isOwnedProfilePictureKey(string $key, int $userId): bool
    {
        return preg_match(
            "#^User/" . preg_quote((string) $userId, "#") . "/profilepicture/[a-f0-9]{32}\\.(jpg|png|webp|avif|gif)$#",
            $key
        ) === 1;
    }

    private static function validateUploadEnvelope(array $file): void
    {
        $error = $file["error"] ?? UPLOAD_ERR_NO_FILE;
        $messages = [
            UPLOAD_ERR_INI_SIZE => "Fichier superieur a la limite serveur",
            UPLOAD_ERR_FORM_SIZE => "Fichier superieur a la limite du formulaire",
            UPLOAD_ERR_PARTIAL => "Upload du fichier incomplet",
            UPLOAD_ERR_NO_FILE => "Aucun fichier recu",
            UPLOAD_ERR_NO_TMP_DIR => "Repertoire temporaire indisponible",
            UPLOAD_ERR_CANT_WRITE => "Ecriture temporaire impossible",
            UPLOAD_ERR_EXTENSION => "Upload bloque par le serveur",
        ];

        if ($error !== UPLOAD_ERR_OK) {
            throw new DomainException($messages[$error] ?? "Erreur d'upload inconnue");
        }

        if (!isset($file["tmp_name"], $file["name"])
            || !is_string($file["tmp_name"])
            || !is_string($file["name"])
            || !is_uploaded_file($file["tmp_name"])
        ) {
            throw new DomainException("Fichier temporaire invalide");
        }
    }

    private static function rejectDangerousOriginalName(string $name): void
    {
        $normalized = strtolower(basename(str_replace("\\", "/", $name)));

        if ($normalized === "" || strlen($normalized) > 255 || str_contains($normalized, "\0")) {
            throw new DomainException("Nom de fichier invalide");
        }

        $parts = explode(".", $normalized);

        foreach (array_slice($parts, 0, -1) as $part) {
            if (in_array($part, self::DANGEROUS_NAME_PARTS, true)) {
                throw new DomainException("Double extension dangereuse detectee");
            }
        }
    }

    private static function validateSignature(string $path, string $mime): void
    {
        $handle = fopen($path, "rb");

        if ($handle === false) {
            throw new DomainException("Lecture du fichier impossible");
        }

        $header = fread($handle, 32);
        fclose($handle);

        if (!is_string($header)) {
            throw new DomainException("Signature du fichier illisible");
        }

        $valid = match ($mime) {
            "image/jpeg" => str_starts_with($header, "\xFF\xD8\xFF"),
            "image/png" => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            "image/gif" => str_starts_with($header, "GIF87a") || str_starts_with($header, "GIF89a"),
            "image/webp" => substr($header, 0, 4) === "RIFF" && substr($header, 8, 4) === "WEBP",
            "image/avif" => substr($header, 4, 4) === "ftyp"
                && (
                    in_array(substr($header, 8, 4), ["avif", "avis"], true)
                    || str_contains(substr($header, 16), "avif")
                    || str_contains(substr($header, 16), "avis")
                ),
            "video/mp4" => substr($header, 4, 4) === "ftyp",
            "video/webm" => str_starts_with($header, "\x1A\x45\xDF\xA3"),
            default => false,
        };

        if (!$valid) {
            throw new DomainException("Signature binaire du media invalide");
        }
    }

    private static function sanitizeImage(string $source, string $mime, string $extension): array
    {
        $dimensions = @getimagesize($source);

        if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1], $dimensions["mime"])
            || $dimensions["mime"] !== $mime
            || $dimensions[0] < 1 || $dimensions[1] < 1
            || $dimensions[0] > self::IMAGE_MAX_DIMENSION || $dimensions[1] > self::IMAGE_MAX_DIMENSION
            || $dimensions[0] * $dimensions[1] > self::IMAGE_MAX_PIXELS
        ) {
            throw new DomainException("Dimensions ou structure de l'image invalides");
        }

        $image = match ($mime) {
            "image/jpeg" => @imagecreatefromjpeg($source),
            "image/png" => @imagecreatefrompng($source),
            "image/gif" => @imagecreatefromgif($source),
            "image/webp" => @imagecreatefromwebp($source),
            "image/avif" => @imagecreatefromavif($source),
            default => false,
        };

        if ($image === false) {
            throw new DomainException("L'image ne peut pas etre decodee de facon sure");
        }

        $sanitizedPath = tempnam(sys_get_temp_dir(), "cms-media-");

        if ($sanitizedPath === false) {
            unset($image);
            throw new RuntimeException("Creation du fichier temporaire impossible");
        }

        try {
            $written = match ($mime) {
                "image/jpeg" => imagejpeg($image, $sanitizedPath, 90),
                "image/png" => imagepng($image, $sanitizedPath, 6),
                "image/gif" => imagegif($image, $sanitizedPath),
                "image/webp" => imagewebp($image, $sanitizedPath, 85),
                "image/avif" => imageavif($image, $sanitizedPath, 70, 6),
                default => false,
            };
            $sanitizedSize = filesize($sanitizedPath);

            if (!$written || $sanitizedSize === false || $sanitizedSize < 1 || $sanitizedSize > self::IMAGE_MAX_BYTES) {
                throw new DomainException("Reencodage securise de l'image impossible");
            }

            self::validateSignature($sanitizedPath, $mime);
        } catch (Throwable $exception) {
            @unlink($sanitizedPath);
            throw $exception;
        } finally {
            unset($image);
        }

        return [
            "path" => $sanitizedPath,
            "cleanup" => true,
            "category" => "images",
            "extension" => $extension,
            "mime_type" => $mime,
            "size" => $sanitizedSize,
            "width" => (int) $dimensions[0],
            "height" => (int) $dimensions[1],
        ];
    }
}
