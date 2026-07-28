<?php

declare(strict_types=1);

use Aws\Result;
use Aws\S3\S3Client;

/**
 * Wraps the S3-compatible MinIO connection used by page and user profile media controllers.
 * Credentials come from Docker environment variables or *_FILE secrets and never reach the frontend.
 * Media endpoints upload, list and stream files between PHP temporary storage, MinIO and authorized visitors.
 */
final class MinioStorage
{
    private S3Client $client;
    private string $bucket;
    private string $sseMode;
    private string $sseKmsKeyId;

    public function __construct()
    {
        $accessKey = envValue("MINIO_ACCESS_KEY", "");
        $secretKey = envValue("MINIO_SECRET_KEY", "");
        $this->bucket = envValue("MINIO_BUCKET", "users-data");
        $this->sseMode = envValue("MINIO_SSE_MODE", "");
        $this->sseKmsKeyId = envValue("MINIO_SSE_KMS_KEY_ID", "");

        if ($accessKey === "" || $secretKey === "" || $this->bucket === "") {
            throw new RuntimeException("Configuration MinIO incomplete");
        }

        if (!in_array($this->sseMode, ["", "AES256", "aws:kms"], true)) {
            throw new RuntimeException("Mode de chiffrement MinIO invalide");
        }

        if ($this->sseMode === "aws:kms" && $this->sseKmsKeyId === "") {
            throw new RuntimeException("Cle KMS MinIO manquante");
        }

        $this->client = new S3Client([
            "version" => "latest",
            "region" => envValue("MINIO_REGION", "us-east-1"),
            "endpoint" => envValue("MINIO_ENDPOINT", "http://minio:9000"),
            "use_path_style_endpoint" => filter_var(envValue("MINIO_PATH_STYLE", "true"), FILTER_VALIDATE_BOOL),
            "credentials" => [
                "key" => $accessKey,
                "secret" => $secretKey,
            ],
            "http" => [
                "connect_timeout" => 5,
                "timeout" => 300,
            ],
        ]);
    }

    public function upload(string $objectKey, array $media): void
    {
        $arguments = [
            "Bucket" => $this->bucket,
            "Key" => $objectKey,
            "SourceFile" => $media["path"],
            "ContentType" => $media["mime_type"],
            "ContentDisposition" => "inline",
            "CacheControl" => "private, max-age=0, no-store",
            "Metadata" => [
                "validated" => "true",
            ],
        ];

        if ($this->sseMode !== "") {
            $arguments["ServerSideEncryption"] = $this->sseMode;
        }

        if ($this->sseMode === "aws:kms") {
            $arguments["SSEKMSKeyId"] = $this->sseKmsKeyId;
        }

        $this->client->putObject($arguments);
    }

    public function read(string $objectKey, ?string $range = null): Result
    {
        $arguments = [
            "Bucket" => $this->bucket,
            "Key" => $objectKey,
        ];

        if ($range !== null) {
            $arguments["Range"] = $range;
        }

        return $this->client->getObject($arguments);
    }

    public function list(string $prefix): array
    {
        $objects = [];
        $continuationToken = null;

        do {
            $arguments = [
                "Bucket" => $this->bucket,
                "Prefix" => $prefix,
            ];

            $continuationToken !== null && ($arguments["ContinuationToken"] = $continuationToken);
            $result = $this->client->listObjectsV2($arguments);

            foreach ($result["Contents"] ?? [] as $object) {
                $key = (string) ($object["Key"] ?? "");

                $key !== "" && ($objects[] = [
                    "key" => $key,
                    "size" => (int) ($object["Size"] ?? 0),
                    "last_modified" => isset($object["LastModified"])
                        ? $object["LastModified"]->format(DATE_ATOM)
                        : null,
                ]);
            }

            $continuationToken = ($result["IsTruncated"] ?? false)
                ? (string) ($result["NextContinuationToken"] ?? "")
                : null;
        } while ($continuationToken !== null && $continuationToken !== "");

        usort($objects, static fn(array $left, array $right): int => (
            strcmp((string) ($right["last_modified"] ?? ""), (string) ($left["last_modified"] ?? ""))
        ));

        return $objects;
    }
}
