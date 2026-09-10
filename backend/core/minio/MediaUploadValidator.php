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
    private const FILE_TYPE_BOX_MAX_BYTES = 64 * 1024;
    private const EBML_HEADER_MAX_BYTES = 64 * 1024;
    private const IMAGE_MIMES = [
        "image/jpeg" => ["extensions" => ["jpg", "jpeg"], "extension" => "jpg"],
        "image/png" => ["extensions" => ["png"], "extension" => "png"],
        "image/webp" => ["extensions" => ["webp"], "extension" => "webp"],
        "image/avif" => ["extensions" => ["avif"], "extension" => "avif"],
        "image/gif" => ["extensions" => ["gif"], "extension" => "gif"],
    ];
    /*
     * limite les extensions de vidéos autoriser à mp4 et webm pour éviter les problèmes de 
     * compatibilité avec les navigateurs et les lecteurs vidéo.
     * Les autres formats peuvent être plus difficiles à lire ou à traiter et peuvent également poser des problèmes de sécurité.
     */
    private const VIDEO_MIMES = [
        "video/mp4" => ["extensions" => ["mp4"], "extension" => "mp4"],
        "video/webm" => ["extensions" => ["webm"], "extension" => "webm"],
    ];

    // sert pour les double extensions comme "image.jpg.php" ou "video.mp4.sh" qui sont dangereuses même si l'extension finale est autorisée
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
            /*
             * Les signatures précédentes confirment le MIME et octets, mais pas les pistes qu'il contient.
             * ffprobe analyse maintenant sa structure : une vraie piste vidéo est obligatoire tandis
             * qu'une piste audio reste facultative avant l'envoi du fichier original vers MinIO.
             */
            MediaStreamProbe::validate($temporaryPath, "video");

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

    /*
     * Vérifie que les octets du fichier correspondent au type MIME détecté par Fileinfo.
     * validate() appelle cette méthode après la validation prioritaire de l'upload PHP, de la taille,
     * du nom, du MIME et de l'extension pour POST /pages/{id}/media, les images de page et de profil.
     *
     * JPEG, PNG, GIF et WebP utilisent une signature fixe. AVIF et MP4 partagent ISO-BMFF : leur boîte
     * ftyp est donc analysée pour distinguer leurs marques. WebM utilise EBML et doit déclarer DocType=webm.
     * Après le réencodage GD d'une image, sanitizeImage() rappelle cette méthode sur le fichier produit.
     */
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
        /*
         * Les signatures binaires sont vérifiées pour éviter les attaques par double extension et les fichiers renommés
         * Les extensions et les types MIME sont déjà validés par validate() avant l'appel de cette méthode
         * 
         * fonctionne par liste blanche inversée : si les octets du fichier ne correspondent pas à la signature attendue pour le type MIME détecté, le fichier est rejeté
         * avif et mp4 sont dans des conteneurs ftyp et ISO-BMFF et nécessitent une analyse plus approfondie pour distinguer les marques autorisées des marques génériques 
         * ou incompatibles
         */
        $valid = match ($mime) {
            "image/jpeg" => str_starts_with($header, "\xFF\xD8\xFF"),
            "image/png" => str_starts_with($header, "\x89PNG\r\n\x1A\n"),
            "image/gif" => str_starts_with($header, "GIF87a") || str_starts_with($header, "GIF89a"),
            "image/webp" => substr($header, 0, 4) === "RIFF" && substr($header, 8, 4) === "WEBP",
            // AVIF accepte les marques avif ou avis et rejette toute boîte ftyp qui ne les contient pas.
            "image/avif" => self::hasIsoBaseMediaBrand($path, ["avif", "avis"]),
            // MP4 exige une marque MP4 ou AVC explicite et rejette les marques génériques, AVIF, HEIF, 3GP et QuickTime seules.
            "video/mp4" => self::hasIsoBaseMediaBrand($path, ["mp41", "mp42", "avc1"]),
            // WebM exige DocType=webm afin de rejeter Matroska et les autres documents EBML.
            "video/webm" => self::hasEbmlDocumentType($path, "webm"),
            default => false,
        };

        if (!$valid) {
            throw new DomainException("Signature binaire du media invalide");
        }
    }

    /*
     * Analyse le contenu du type ftyp d'un fichier ISO-BMFF et compare ses marques à une liste blanche.
     * rejette les extensions de fichiers qui ne correspondent pas à leur contenu réel
     *  même si elles ont été renommées pour correspondre à un type MIME autorisé.
     *
     * Pour AVIF, validateSignature() transmet avif et avis : mif1 reste valable uniquement si l'une de
     * ces marques compatibles est présente. Pour MP4, elle transmet mp41, mp42 et avc1 : isom et les
     * versions ISO génériques ne suffisent pas seules, pas plus que les marques HEIF, 3GP ou QuickTime.
     */
    private static function hasIsoBaseMediaBrand(string $path, array $allowedBrands): bool
    {
        $handle = fopen($path, "rb");

        if ($handle === false) {
            return false;
        }

        try {
            $boxHeader = self::readBytes($handle, 8);

            if ($boxHeader === null || substr($boxHeader, 4, 4) !== "ftyp") {
                return false;
            }

            $boxSize = unpack("Nsize", substr($boxHeader, 0, 4))["size"];
            $headerSize = 8;

            if ($boxSize === 1) {
                $extendedSize = self::readBytes($handle, 8);

                if ($extendedSize === null) {
                    return false;
                }

                $parts = unpack("Nhigh/Nlow", $extendedSize);

                if ($parts["high"] !== 0) {
                    return false;
                }

                $boxSize = $parts["low"];
                $headerSize = 16;
            }

            if ($boxSize < $headerSize + 8 || $boxSize > self::FILE_TYPE_BOX_MAX_BYTES) {
                return false;
            }

            $statistics = fstat($handle);

            if (!is_array($statistics) || !isset($statistics["size"]) || $boxSize > $statistics["size"]) {
                return false;
            }

            $payload = self::readBytes($handle, $boxSize - $headerSize);

            if ($payload === null || (strlen($payload) - 8) % 4 !== 0) {
                return false;
            }

            if (in_array(substr($payload, 0, 4), $allowedBrands, true)) {
                return true;
            }

            for ($offset = 8, $length = strlen($payload); $offset < $length; $offset += 4) {
                if (in_array(substr($payload, $offset, 4), $allowedBrands, true)) {
                    return true;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    /*
     * Lit exactement le nombre d'octets demandé depuis un fichier binaire déjà ouvert.
     * Les parseurs ftyp et EBML l'utilisent pour rejeter un en-tête tronqué
     * comme une tentative de masquer un format non autorisé derrière une signature partiel "correct"
     */
    private static function readBytes($handle, int $length): ?string
    {
        $contents = "";

        while (strlen($contents) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($contents));

            if ($chunk === false || $chunk === "") {
                return null;
            }

            $contents .= $chunk;
        }

        return strlen($contents) === $length ? $contents : null;
    }

    /*
     * Analyse l'en-tête EBML afin de distinguer WebM des autres formats fondés sur EBML.
     * WebM et Matroska(MKV) partagent la signature 1A 45 DF A3 : cette signature seule ne permet donc pas
     * de garantir qu'un fichier envoyé comme .webm correspond réellement au MIME video/webm.
     *
     * Seul un élément DocType unique égal à webm est accepté. DocType=matroska est rejeté pour éviter
     * de stocker un MKV renommé dans MinIO puis de le servir avec un type MIME incorrect. La taille de
     * l'en-tête est bornée et chaque élément doit rester dans les limites déclarées du fichier.
     */
    private static function hasEbmlDocumentType(string $path, string $allowedDocumentType): bool
    {
        $handle = fopen($path, "rb");

        if ($handle === false) {
            return false;
        }

        try {
            if (self::readBytes($handle, 4) !== "\x1A\x45\xDF\xA3") {
                return false;
            }

            $headerSize = self::readEbmlVariableInteger($handle);

            if ($headerSize === null
                || $headerSize["unknown"]
                || $headerSize["value"] > self::EBML_HEADER_MAX_BYTES
            ) {
                return false;
            }

            $headerStart = ftell($handle);
            $statistics = fstat($handle);

            if ($headerStart === false
                || !is_array($statistics)
                || !isset($statistics["size"])
                || $headerStart + $headerSize["value"] > $statistics["size"]
            ) {
                return false;
            }

            $headerEnd = $headerStart + $headerSize["value"];
            $documentType = null;

            while (($position = ftell($handle)) !== false && $position < $headerEnd) {
                $elementId = self::readEbmlElementId($handle);
                $elementSize = self::readEbmlVariableInteger($handle);
                $dataStart = ftell($handle);

                if ($elementId === null
                    || $elementSize === null
                    || $elementSize["unknown"]
                    || $dataStart === false
                    || $dataStart + $elementSize["value"] > $headerEnd
                ) {
                    return false;
                }

                /*
                 * L'identifiant EBML 0x4282 correspond à l'élément DocType.
                 * Sa valeur est extraite ici : elle vaut "webm" pour un WebM et "matroska" pour un MKV.
                 * Un second élément DocType ou une valeur vide rend l'en-tête ambigu et provoque son rejet.
                 */
                if ($elementId === "\x42\x82") {
                    if ($documentType !== null || $elementSize["value"] < 1) {
                        return false;
                    }

                    $documentType = self::readBytes($handle, $elementSize["value"]);

                    if ($documentType === null) {
                        return false;
                    }

                    continue;
                }

                if (fseek($handle, $elementSize["value"], SEEK_CUR) !== 0) {
                    return false;
                }
            }

            /*
             * si la signature est webm, le fichier est accepté
             * sinon il est rejeté pour éviter de stocker un MKV renommé en WebM ou un fichier EBML inconnu
             */
            return ftell($handle) === $headerEnd && $documentType === $allowedDocumentType;
        } finally {
            fclose($handle);
        }
    }

    /*
     * Lit l'identifiant de longueur variable d'un élément contenu dans l'en-tête EBML.
     * Les identifiants supérieurs à quatre octets sont rejetés conformément aux limites de cet en-tête.
     */

    private static function readEbmlElementId($handle): ?string
    {
        $firstByte = self::readBytes($handle, 1);

        if ($firstByte === null) {
            return null;
        }

        $length = self::ebmlVariableIntegerLength(ord($firstByte));

        if ($length === null || $length > 4) {
            return null;
        }

        $remainingBytes = self::readBytes($handle, $length - 1);

        return $remainingBytes === null ? null : $firstByte . $remainingBytes;
    }

    /*
     * Décode un entier EBML de longueur variable utilisé pour déclarer la taille d'un élément.
     * La valeur décodée et l'indicateur de taille inconnue sont retournés séparément afin que le parseur
     * refuse les tailles inconnues dans l'en-tête contrôlé au lieu de lire au-delà de ses limites.
     */
    private static function readEbmlVariableInteger($handle): ?array
    {
        $firstByte = self::readBytes($handle, 1);

        if ($firstByte === null) {
            return null;
        }

        $firstValue = ord($firstByte);
        $length = self::ebmlVariableIntegerLength($firstValue);

        if ($length === null) {
            return null;
        }

        $value = $firstValue & (0xFF >> $length);
        $unknown = $value === (0xFF >> $length);

        for ($index = 1; $index < $length; $index++) {
            $byte = self::readBytes($handle, 1);

            if ($byte === null) {
                return null;
            }

            $byteValue = ord($byte);
            $value = ($value << 8) | $byteValue;
            $unknown = $unknown && $byteValue === 0xFF;
        }

        return ["value" => $value, "unknown" => $unknown];
    }

    /*
     * Détermine la longueur d'un entier EBML grâce au premier bit actif de son premier octet.
     * Une valeur sans bit marqueur ou dépassant les huit octets autorisés est considérée invalide.
     */
    private static function ebmlVariableIntegerLength(int $firstByte): ?int
    {
        for ($length = 1, $mask = 0x80; $length <= 8; $length++, $mask >>= 1) {
            if (($firstByte & $mask) !== 0) {
                return $length;
            }
        }

        return null;
    }
    /*
    * verifie la validité de l'image (taille, dimensions, mime)
    * recréer l'image et la nettoie pour éviter les attaques par 
    * injection de code dans les métadonnées
    * puis appele re-appel validateSignature() pour vérifier que l'image re-encodée est valide
    */
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
