<?php

declare(strict_types=1);

/**
 * Exercises every HTTP endpoint registered by public/index.php and verifies the MinIO initialization fixture.
 * Run it manually inside the development backend container after minio-init has populated the test fixture:
 * docker compose exec backend php tests/minio/ApiMinioIntegrationTest.php
 * Data follows test HTTP client -> Apache route -> controller -> model -> MySQL or MinIO -> HTTP response.
 * The test creates a unique user, page, revisions and media object, then removes only those resources in cleanup.
 */

use Aws\S3\S3Client;

require_once __DIR__ . "/../../vendor/autoload.php";

const TEST_BASE_URL = "http://127.0.0.1";
const MINIO_INIT_KEY = "integration-tests/minio-init.txt";

$cookies = [];
$createdUserId = null;
$uploadedObjectKey = null;
$temporaryImagePath = null;
$testFailure = null;

function expectIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function responseJson(array $response): array
{
    $decoded = json_decode($response["body"], true);

    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException("Expected a JSON response, received: " . substr($response["body"], 0, 500));
    }

    return $decoded;
}

function requestApi(
    string $method,
    string $path,
    ?array $json = null,
    array $extraHeaders = [],
    ?string $rawBody = null,
    bool $withSession = true
): array {
    global $cookies;

    $headers = [
        "Accept: application/json",
        "Origin: " . envValue("FRONTEND_URL", "http://localhost:8080"),
        "Connection: close",
    ];

    if ($withSession && $cookies !== []) {
        $cookieValues = [];

        foreach ($cookies as $name => $value) {
            $cookieValues[] = $name . "=" . $value;
        }

        $headers[] = "Cookie: " . implode("; ", $cookieValues);
    }

    if ($json !== null) {
        $rawBody = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headers[] = "Content-Type: application/json";
    }

    $headers = array_merge($headers, $extraHeaders);
    $options = [
        "http" => [
            "method" => $method,
            "header" => implode("\r\n", $headers),
            "ignore_errors" => true,
            "timeout" => 30,
            "protocol_version" => 1.1,
        ],
    ];

    if ($rawBody !== null) {
        $options["http"]["content"] = $rawBody;
    }

    $body = @file_get_contents(TEST_BASE_URL . $path, false, stream_context_create($options));
    $responseHeaders = $http_response_header ?? [];

    if ($body === false || $responseHeaders === []) {
        $error = error_get_last();
        throw new RuntimeException("HTTP request failed for " . $method . " " . $path . ": " . ($error["message"] ?? "unknown error"));
    }

    if (preg_match("#^HTTP/\\S+\\s+(\\d{3})#", $responseHeaders[0], $matches) !== 1) {
        throw new RuntimeException("Invalid HTTP status for " . $method . " " . $path);
    }

    $status = (int) $matches[1];

    if ($withSession) {
        foreach ($responseHeaders as $header) {
            if (preg_match("/^Set-Cookie:\\s*([^=;]+)=([^;]*)/i", $header, $matches) !== 1) {
                continue;
            }

            $name = trim($matches[1]);
            $value = rawurldecode($matches[2]);

            if ($value === "") {
                unset($cookies[$name]);
                continue;
            }

            $cookies[$name] = $value;
        }
    }

    return [
        "status" => $status,
        "headers" => $responseHeaders,
        "body" => $body,
    ];
}

function expectStatus(array $response, int $status, string $label): array
{
    if ($response["status"] !== $status) {
        $details = str_starts_with((string) ($response["body"] ?? ""), "{")
            ? (string) $response["body"]
            : substr((string) ($response["body"] ?? ""), 0, 500);
        throw new RuntimeException($label . " expected HTTP " . $status . ", received " . $response["status"] . ": " . $details);
    }

    return $response;
}

function responseHeader(array $response, string $name): ?string
{
    foreach ($response["headers"] as $header) {
        if (stripos($header, $name . ":") === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }

    return null;
}

function minioClient(string $accessKey, string $secretKey): S3Client
{
    return new S3Client([
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
            "timeout" => 30,
        ],
    ]);
}

function multipartImageBody(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Unable to read the temporary integration image");
    }

    $boundary = "--------------------------" . bin2hex(random_bytes(12));
    $lineBreak = "\r\n";
    $body = "--" . $boundary . $lineBreak
        . "Content-Disposition: form-data; name=\"media_type\"" . $lineBreak . $lineBreak
        . "image" . $lineBreak
        . "--" . $boundary . $lineBreak
        . "Content-Disposition: form-data; name=\"file\"; filename=\"integration.png\"" . $lineBreak
        . "Content-Type: image/png" . $lineBreak . $lineBreak
        . $contents . $lineBreak
        . "--" . $boundary . "--" . $lineBreak;

    return [
        "body" => $body,
        "contentType" => "multipart/form-data; boundary=" . $boundary,
    ];
}

try {
    $bucket = envValue("MINIO_BUCKET", "users-data");
    $accessKey = envValue("MINIO_ACCESS_KEY", "");
    $secretKey = envValue("MINIO_SECRET_KEY", "");
    expectIntegration($accessKey !== "" && $secretKey !== "", "MinIO backend credentials are missing");

    // Verify the exact file copied from backend/tests/minio/fixtures by minio-init.
    $fixturePath = __DIR__ . "/fixtures/minio-init.txt";
    $fixtureContents = file_get_contents($fixturePath);
    expectIntegration($fixtureContents !== false, "The local minio-init fixture is missing");

    try {
        $storedFixture = minioClient($accessKey, $secretKey)->getObject([
            "Bucket" => $bucket,
            "Key" => MINIO_INIT_KEY,
        ]);
    } catch (Throwable $exception) {
        throw new RuntimeException(
            "The minio-init fixture is unavailable. Run docker compose run --rm minio-init before this test.",
            0,
            $exception
        );
    }

    expectIntegration((string) $storedFixture["Body"] === $fixtureContents, "The MinIO fixture differs from backend/tests/minio/fixtures/minio-init.txt");
    echo "[OK] minio-init fixture\n";

    expectStatus(requestApi("GET", "/api"), 200, "API health");
    echo "[OK] GET /api\n";

    $token = bin2hex(random_bytes(6));
    $username = "integration_" . $token;
    $email = $username . "@example.test";
    $password = "Integration-" . $token . "-pass";

    $register = responseJson(expectStatus(requestApi("POST", "/api/register", [
        "username" => $username,
        "useremail" => $email,
        "userpassword" => $password,
    ]), 201, "Register"));
    $createdUserId = (int) ($register["user"]["id"] ?? 0);
    expectIntegration($createdUserId > 0, "Registration did not return the created user id");
    echo "[OK] POST /api/register\n";

    $me = responseJson(expectStatus(requestApi("GET", "/api/me"), 200, "Current user"));
    expectIntegration((int) ($me["user"]["id"] ?? 0) === $createdUserId, "GET /api/me returned another user");
    echo "[OK] GET /api/me\n";

    $filters = responseJson(expectStatus(requestApi("GET", "/api/filters?type=theme"), 200, "Filter list"));
    expectIntegration(is_array($filters["filters"] ?? null), "Filter endpoint did not return a list");
    echo "[OK] GET /api/filters\n";

    $createdPage = responseJson(expectStatus(requestApi("POST", "/api/pages", [
        "page_title" => "Integration " . $token,
    ]), 201, "Page creation"));
    $pageId = (int) ($createdPage["page"]["id"] ?? 0);
    expectIntegration($pageId > 0, "Page creation did not return a page id");
    echo "[OK] POST /api/pages\n";

    $myPages = responseJson(expectStatus(requestApi("GET", "/api/me/pages"), 200, "Page owner list"));
    expectIntegration(in_array($pageId, array_map("intval", array_column($myPages["pages"] ?? [], "id")), true), "GET /api/me/pages omitted the test page");
    echo "[OK] GET /api/me/pages\n";

    $ownedPages = responseJson(expectStatus(requestApi("GET", "/api/me/owned-pages"), 200, "Owned page list"));
    expectIntegration(in_array($pageId, array_map("intval", array_column($ownedPages["pages"] ?? [], "id")), true), "GET /api/me/owned-pages omitted the test page");
    echo "[OK] GET /api/me/owned-pages\n";

    expectStatus(requestApi("PATCH", "/api/pages/" . $pageId . "/title", [
        "page_title" => "Integration updated " . $token,
    ]), 200, "Title update");
    expectStatus(requestApi("PATCH", "/api/pages/" . $pageId . "/description", [
        "page_description" => "Temporary integration page",
    ]), 200, "Description update");
    expectStatus(requestApi("PATCH", "/api/pages/" . $pageId . "/picture", [
        "page_picture" => "/integration/picture.png",
    ]), 200, "Picture update");
    expectStatus(requestApi("PATCH", "/api/pages/" . $pageId . "/status", [
        "page_status" => "public",
    ]), 200, "Status update to public");
    expectStatus(requestApi("PATCH", "/api/pages/" . $pageId . "/status", [
        "page_status" => "private",
    ]), 200, "Status update to private");
    echo "[OK] PATCH /api/pages/{id}/{title|description|picture|status}\n";

    $draft = responseJson(expectStatus(requestApi("GET", "/api/pages/" . $pageId . "/draft"), 200, "Draft read"));
    expectIntegration((int) ($draft["revision"]["page_id"] ?? 0) === $pageId, "Draft endpoint returned another page");
    echo "[OK] GET /api/pages/{id}/draft\n";

    expectStatus(requestApi("GET", "/api/pages/" . $pageId, null, [], null, false), 404, "Anonymous private page read");

    $temporaryImagePath = tempnam(sys_get_temp_dir(), "api-minio-test-");
    expectIntegration($temporaryImagePath !== false, "Unable to create the temporary upload file");
    $image = imagecreatetruecolor(2, 2);
    expectIntegration($image !== false, "Unable to create the integration image");
    $color = imagecolorallocate($image, 30, 120, 210);
    imagefill($image, 0, 0, $color);
    expectIntegration(imagepng($image, $temporaryImagePath), "Unable to write the integration image");
    imagedestroy($image);

    $multipart = multipartImageBody($temporaryImagePath);
    $uploaded = responseJson(expectStatus(requestApi(
        "POST",
        "/api/pages/" . $pageId . "/media",
        null,
        ["Content-Type: " . $multipart["contentType"]],
        $multipart["body"]
    ), 201, "Media upload"));
    $uploadedObjectKey = (string) ($uploaded["media"]["objectKey"] ?? "");
    expectIntegration($uploadedObjectKey !== "", "Media upload did not return an object key");
    echo "[OK] POST /api/pages/{id}/media\n";

    $mediaPath = "/api/pages/" . $pageId . "/media?key=" . rawurlencode($uploadedObjectKey);
    $media = expectStatus(requestApi("GET", $mediaPath), 200, "Media read");
    expectIntegration(responseHeader($media, "Content-Type") === "image/png", "Media endpoint returned an unexpected MIME type");
    expectIntegration(str_starts_with($media["body"], "\x89PNG\r\n\x1A\n"), "Media endpoint did not return PNG data");

    $partialMedia = expectStatus(requestApi("GET", $mediaPath, null, ["Range: bytes=0-7"]), 206, "Partial media read");
    expectIntegration($partialMedia["body"] === substr($media["body"], 0, 8), "Partial media response does not match the full object");
    echo "[OK] GET /api/pages/{id}/media with full and range reads\n";

    $document = [
        "schemaVersion" => 1,
        "settings" => [
            "desktopColumns" => 12,
            "responsiveStrategy" => "auto-stack",
        ],
        "blocks" => [
            "image-1" => [
                "id" => "image-1",
                "type" => "image",
                "props" => [
                    "label" => "Integration image",
                    "objectKey" => $uploadedObjectKey,
                    "content" => "",
                ],
            ],
        ],
        "layouts" => [
            "lg" => [[
                "i" => "image-1",
                "parentId" => null,
                "x" => 0,
                "y" => 0,
                "w" => 4,
                "h" => 3,
            ]],
            "md" => [],
            "sm" => [],
            "xs" => [],
        ],
    ];

    expectStatus(requestApi("PUT", "/api/pages/" . $pageId . "/draft", [
        "pagecontent" => $document,
    ]), 200, "Draft save");
    $document["blocks"]["image-1"]["props"]["label"] = "Integration image updated";
    expectStatus(requestApi("PATCH", "/api/pages/" . $pageId . "/content", [
        "pagecontent" => $document,
    ]), 200, "Content compatibility update");
    echo "[OK] PUT draft and PATCH content\n";

    expectStatus(requestApi("POST", "/api/pages/" . $pageId . "/publish", [
        "is_anonymous" => "invalid",
    ]), 422, "Invalid publication visibility");
    $publication = responseJson(expectStatus(requestApi("POST", "/api/pages/" . $pageId . "/publish", [
        "is_anonymous" => false,
    ]), 200, "Draft publication"));
    expectIntegration(
        ($publication["page_status"] ?? null) === "public"
            && ($publication["is_anonymous"] ?? null) === false,
        "Draft publication did not apply public named visibility"
    );
    $publishedPage = responseJson(expectStatus(requestApi("GET", "/api/pages/" . $pageId), 200, "Owner published page read"));
    expectIntegration(
        ($publishedPage["page"]["pagecontent"]["blocks"]["image-1"]["props"]["objectKey"] ?? null) === $uploadedObjectKey,
        "Published content omitted the uploaded object key"
    );
    expectIntegration(
        ($publishedPage["page"]["status_badge"] ?? null) === "public",
        "Page owner cannot view the public publication badge"
    );
    expectStatus(requestApi("GET", "/api/pages/" . $pageId . "/draft"), 200, "Anonymous publication draft recreation");
    $anonymousPublication = responseJson(expectStatus(requestApi("POST", "/api/pages/" . $pageId . "/publish", [
        "is_anonymous" => true,
    ]), 200, "Anonymous draft publication"));
    expectIntegration(
        ($anonymousPublication["page_status"] ?? null) === "public"
            && ($anonymousPublication["is_anonymous"] ?? null) === true,
        "Draft publication did not apply public anonymous visibility"
    );
    echo "[OK] named and anonymous POST publish, then GET /api/pages/{id}\n";

    expectStatus(requestApi("POST", "/api/me/owned-pages/" . $pageId . "/settings", [
        "page_status" => "public",
        "is_anonymous" => true,
    ]), 200, "Owned page settings");
    echo "[OK] POST /api/me/owned-pages/{id}/settings\n";

    $publicPages = responseJson(expectStatus(requestApi("GET", "/api/pages"), 200, "Public page list"));
    expectIntegration(in_array($pageId, array_map("intval", array_column($publicPages["pages"] ?? [], "id")), true), "GET /api/pages omitted the public test page");

    $anonymousPage = responseJson(expectStatus(requestApi("GET", "/api/pages/" . $pageId, null, [], null, false), 200, "Anonymous public page read"));
    expectIntegration(
        array_key_exists("owner_user_id", $anonymousPage["page"] ?? [])
            && $anonymousPage["page"]["owner_user_id"] === null,
        "Anonymous page exposed its owner"
    );
    expectIntegration(
        ($anonymousPage["page"]["status_badge"] ?? null) === null,
        "Anonymous visitor can view the publication status badge"
    );
    expectStatus(requestApi("GET", $mediaPath, null, [], null, false), 200, "Anonymous public media read");
    echo "[OK] public and anonymous page/media reads\n";

    expectStatus(requestApi("POST", "/api/logout"), 200, "Logout");
    expectStatus(requestApi("GET", "/api/me"), 401, "Logged-out session");
    echo "[OK] POST /api/logout\n";

    expectStatus(requestApi("POST", "/api/login", [
        "useremail" => $email,
        "userpassword" => $password,
    ]), 200, "Login");
    expectStatus(requestApi("GET", "/api/me"), 200, "Session after login");
    echo "[OK] POST /api/login\n";
} catch (Throwable $exception) {
    $testFailure = $exception;
} finally {
    $cleanupFailures = [];

    if (is_string($temporaryImagePath) && is_file($temporaryImagePath) && !unlink($temporaryImagePath)) {
        $cleanupFailures[] = "temporary image";
    }

    if (is_string($uploadedObjectKey) && $uploadedObjectKey !== "") {
        try {
            $rootAccessKey = envValue("MINIO_ROOT_USER", "");
            $rootSecretKey = envValue("MINIO_ROOT_PASSWORD", "");
            expectIntegration($rootAccessKey !== "" && $rootSecretKey !== "", "MinIO root credentials are required for test cleanup");
            minioClient($rootAccessKey, $rootSecretKey)->deleteObject([
                "Bucket" => envValue("MINIO_BUCKET", "users-data"),
                "Key" => $uploadedObjectKey,
            ]);
        } catch (Throwable $exception) {
            $cleanupFailures[] = "MinIO object " . $uploadedObjectKey . " (" . $exception->getMessage() . ")";
        }
    }

    if (is_int($createdUserId) && $createdUserId > 0) {
        try {
            // No application delete method exists; deleting the unique test user triggers the declared SQL cascades.
            $statement = $pdo->prepare("DELETE FROM users WHERE id = :id");
            $statement->execute([":id" => $createdUserId]);
            expectIntegration($statement->rowCount() === 1, "The integration user was not deleted");
        } catch (Throwable $exception) {
            $cleanupFailures[] = "SQL user " . $createdUserId . " (" . $exception->getMessage() . ")";
        }
    }

    if ($cleanupFailures !== []) {
        $cleanupException = new RuntimeException("Cleanup failed for: " . implode(", ", $cleanupFailures));
        $testFailure = $testFailure === null
            ? $cleanupException
            : new RuntimeException($testFailure->getMessage() . "; " . $cleanupException->getMessage(), 0, $testFailure);
    }
}

if ($testFailure !== null) {
    fwrite(STDERR, "ApiMinioIntegrationTest FAILED: " . $testFailure->getMessage() . "\n");
    exit(1);
}

echo "ApiMinioIntegrationTest: OK (temporary SQL and MinIO data removed)\n";
