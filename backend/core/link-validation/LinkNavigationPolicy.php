<?php

declare(strict_types=1);

/**
 * Classifies dangerous download paths and protects internal application routes throughout safe-link validation.
 * Local checks reject a matching path immediately; remote checks combine it with forced-download response headers.
 */
final class LinkNavigationPolicy
{
    private const INTERNAL_HTML_EXTENSIONS = ["html", "htm"];
    private const FORBIDDEN_DOWNLOAD_EXTENSIONS = [
        "7z", "apk", "appx", "bat", "bin", "bz2", "cmd", "com", "deb", "dmg", "doc", "docm",
        "docx", "elf", "exe", "gz", "iso", "jar", "msi", "msix", "odp", "ods", "odt", "pdf",
        "pkg", "ppt", "pptm", "pptx", "ps1", "rar", "rpm", "rtf", "sh", "svg", "tar", "tgz",
        "wasm", "xls", "xlsm", "xlsx", "xz", "zip",
    ];

    /*
     * Applique isDirectDownload() aux routes internes et aux URL traitées en mode LOCAL.
     * Un résultat positif produit une DomainException avant l'enregistrement du lien ou du brouillon.
     * En mode REMOTE, RemoteContentInspector utilise directement isDirectDownload() sur chaque redirection.
     */
    public function assertAllowed(LinkTarget $target): void
    {
        if ($this->isDirectDownload($target)) {
            throw new DomainException("Les liens directs vers des fichiers telechargeables sont interdits");
        }
    }

    /*
     * Entrée : path du LinkTarget, éventuellement encodé plusieurs fois.
     * Contrôles : caractères de contrôle après décodage, route API interne et extension finale à haut risque.
     * Sortie : true pour une destination interdite. Les extensions d'image, texte, audio et vidéo restent autorisées
     * et dépendent ensuite de Content-Disposition et application/octet-stream dans RemoteContentInspector.
     */
    public function isDirectDownload(LinkTarget $target): bool
    {
        $decodedPath = $this->decodeRepeatedly($target->path);
        $extension = strtolower(pathinfo($decodedPath, PATHINFO_EXTENSION));
        $invalidDecodedPath = preg_match("/[\x00-\x1F\x7F\\\\]/", $decodedPath) === 1;
        $forbiddenInternalTarget = $target->isInternal()
            && (($extension !== "" && !in_array($extension, self::INTERNAL_HTML_EXTENSIONS, true))
                || $this->isForbiddenInternalApiRoute($decodedPath));

        return $invalidDecodedPath
            || $forbiddenInternalTarget
            || ($extension !== "" && in_array($extension, self::FORBIDDEN_DOWNLOAD_EXTENSIONS, true));
    }

    /*
     * Classe les routes internes /api comme destinations de navigation interdites.
     * Exceptions : routes de lecture me/picture et pages/{id}/media|picture|owner-picture, déjà protégées par les
     * contrôles de propriétaire, de page et de clé MinIO de leurs contrôleurs.
     */
    private function isForbiddenInternalApiRoute(string $path): bool
    {
        if (preg_match("#^/api(?:/|$)#i", $path) !== 1) {
            return false;
        }

        return preg_match(
            "#^/api/(?:me/picture|pages/[0-9]+/(?:media|picture|owner-picture))/?$#i",
            $path,
        ) !== 1;
    }

    /*
     * Applique rawurldecode jusqu'à stabilisation de la chaîne.
     * Produit le chemin utilisé pour les contrôles d'extension et de route, y compris avec un encodage imbriqué
     * comme %252Eexe.
     */
    private function decodeRepeatedly(string $value): string
    {
        while (true) {
            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                return $value;
            }

            $value = $decoded;
        }
    }
}
