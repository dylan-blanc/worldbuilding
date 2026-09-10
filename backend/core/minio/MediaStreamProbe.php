<?php

declare(strict_types=1);

/**
 * Inspecte les pistes des médias temporaires avec ffprobe avant leur stockage dans MinIO.
 * MediaUploadValidator::validate() l'utilise après les contrôles upload → MIME → extension → octets
 * pour confirmer qu'un POST /pages/{id}/media déclaré comme vidéo contient une vraie piste vidéo.
 * La même validation pourra exiger une piste audio (plus tard)
 */
final class MediaStreamProbe
{
    private const EXECUTABLE = "/usr/bin/ffprobe";
    private const TIMEOUT_SECONDS = 10.0;
    private const MAX_OUTPUT_BYTES = 64 * 1024;

    /**
     * Exécute ffprobe sans shell et exige au moins une piste du type demandé.
     * Le fichier est déjà limité et identifié par MediaUploadValidator ; ffprobe complète ces contrôles
     * en analysant la structure interne. Une image de couverture MP4 n'est pas considérée comme une vidéo.
     * Le codec doit être reconnu et une vidéo doit avoir des dimensions positives. Les pistes audio
     * restent facultatives pendant un upload vidéo.
     */
    public static function validate(string $path, string $requiredStreamType): void
    {
        if (!in_array($requiredStreamType, ["video", "audio"], true)) {
            throw new InvalidArgumentException("Type de piste requis invalide");
        }

        $result = self::run($path);

        try {
            $probe = json_decode($result, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Reponse ffprobe invalide", 0, $exception);
        }

        if (!is_array($probe) || !isset($probe["streams"]) || !is_array($probe["streams"])) {
            throw new DomainException("Structure des pistes du media invalide");
        }

        foreach ($probe["streams"] as $stream) {
            if (!is_array($stream) || ($stream["codec_type"] ?? null) !== $requiredStreamType) {
                continue;
            }

            $codec = $stream["codec_name"] ?? null;

            if (!is_string($codec) || $codec === "" || $codec === "unknown") {
                continue;
            }

            if ($requiredStreamType === "audio") {
                return;
            }

            if ((int) ($stream["disposition"]["attached_pic"] ?? 0) !== 1
                && (int) ($stream["width"] ?? 0) > 0
                && (int) ($stream["height"] ?? 0) > 0
            ) {
                return;
            }
        }

        throw new DomainException(
            $requiredStreamType === "video"
                ? "Le fichier ne contient aucune piste video"
                : "Le fichier ne contient aucune piste audio"
        );
    }

    /**
     * Lance ffprobe avec un chemin transmis comme argument direct, sans interprétation par un shell.
     * Seul le protocole file est autorisé afin qu'un média ne provoque aucune requête réseau. Le temps
     * d'exécution et la quantité de sortie sont bornés pour protéger les processus PHP contre un fichier
     * volontairement complexe ou un ffprobe bloqué.
     */
    private static function run(string $path): string
    {
        if (!is_executable(self::EXECUTABLE)) {
            throw new RuntimeException("Executable ffprobe indisponible");
        }

        $command = [
            self::EXECUTABLE,
            "-v",
            "error",
            "-protocol_whitelist",
            "file",
            "-analyzeduration",
            "5000000",
            "-probesize",
            "10485760",
            "-show_entries",
            "stream=codec_type,codec_name,width,height:stream_disposition=attached_pic",
            "-of",
            "json",
            $path,
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ["file", "/dev/null", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes,
            null,
            null,
            ["bypass_shell" => true],
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Demarrage de ffprobe impossible");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $standardOutput = "";
        $errorOutput = "";
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $failure = null;
        $status = proc_get_status($process);

        while ($status["running"]) {
            self::appendAvailableOutput($pipes, $standardOutput, $errorOutput);

            if (strlen($standardOutput) + strlen($errorOutput) > self::MAX_OUTPUT_BYTES) {
                $failure = "Sortie ffprobe superieure a la limite autorisee";
                break;
            }

            if (microtime(true) >= $deadline) {
                $failure = "Analyse ffprobe trop longue";
                break;
            }

            usleep(10_000);
            $status = proc_get_status($process);
        }

        if ($failure !== null && $status["running"]) {
            proc_terminate($process, 9);
        }

        self::appendAvailableOutput($pipes, $standardOutput, $errorOutput);

        if ($failure === null && strlen($standardOutput) + strlen($errorOutput) > self::MAX_OUTPUT_BYTES) {
            $failure = "Sortie ffprobe superieure a la limite autorisee";
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $reportedExitCode = $status["exitcode"];
        $closedExitCode = proc_close($process);

        if ($failure !== null) {
            throw new DomainException($failure);
        }

        $exitCode = $reportedExitCode >= 0 ? $reportedExitCode : $closedExitCode;

        if ($exitCode !== 0) {
            throw new DomainException("Le contenu de la video ne peut pas etre analyse");
        }

        if (trim($errorOutput) !== "") {
            throw new DomainException("La structure de la video contient des erreurs");
        }

        return $standardOutput;
    }

    /**
     * Vide régulièrement stdout et stderr pour empêcher ffprobe de bloquer lorsque ses tubes sont pleins.
     * Les deux sorties contribuent à la même limite, mais seule la sortie JSON standard est interprétée.
     */
    private static function appendAvailableOutput(array $pipes, string &$standardOutput, string &$errorOutput): void
    {
        $standardChunk = stream_get_contents($pipes[1]);
        $errorChunk = stream_get_contents($pipes[2]);
        $standardOutput .= is_string($standardChunk) ? $standardChunk : "";
        $errorOutput .= is_string($errorChunk) ? $errorChunk : "";
    }
}
