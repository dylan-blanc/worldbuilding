<?php

declare(strict_types=1);

/**
 * Exercises the CMS allow-list and administrator-removal replacement guard used by draft endpoints.
 * Run this smoke test inside the backend container after changing CmsContentValidator or the frontend JSON schema.
 * The tested flow mirrors frontend JSON -> PageController -> CmsContentValidator before any SQL write occurs.
 */

require_once __DIR__ . "/../core/CmsContentValidator.php";
require_once __DIR__ . "/../core/CmsModerationGuard.php";
require_once __DIR__ . "/../models/ModerationDecision.php";

function expectCmsTest(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException("Failed CMS assertion: " . $label);
    }
}

function expectDomainException(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (DomainException) {
        return;
    }

    throw new RuntimeException("Expected DomainException: " . $label);
}

$document = CmsContentValidator::emptyDocument();
$document["blocks"]["section-1"] = [
    "id" => "section-1",
    "type" => "section",
    "props" => [
        "label" => "Zone",
        "content" => "",
        "objectKey" => null,
    ],
];
$document["blocks"]["text-1"] = [
    "id" => "text-1",
    "type" => "text",
    "props" => [
        "label" => "Texte",
        "objectKey" => null,
        "content" => [
            "type" => "doc",
            "content" => [[
                "type" => "paragraph",
                "attrs" => ["textAlign" => "center"],
                "content" => [[
                    "type" => "text",
                    "marks" => [[
                        "type" => "textStyle",
                        "attrs" => [
                            "color" => "#112233",
                            "fontFamily" => "serif",
                            "fontSize" => "18px",
                            "lineHeight" => "1.5",
                        ],
                    ], [
                        "type" => "link",
                        "attrs" => [
                            "href" => "https://example.com/article",
                            "target" => "_blank",
                            "rel" => "noopener noreferrer nofollow",
                            "class" => null,
                        ],
                    ]],
                    "text" => "Texte sûr",
                ]],
            ]],
        ],
    ],
];
$document["layouts"]["lg"] = [[
    "i" => "section-1",
    "parentId" => null,
    "x" => 0,
    "y" => 0,
    "w" => 12,
    "h" => 6,
], [
    "i" => "text-1",
    "parentId" => "section-1",
    "x" => 0,
    "y" => 0,
    "w" => 6,
    "h" => 3,
]];

CmsContentValidator::validate($document, 7, 12);

expectDomainException(static function () use ($document): void {
    $nestedSection = $document;
    $nestedSection["layouts"]["lg"][0]["parentId"] = "section-1";
    CmsContentValidator::validate($nestedSection, 7, 12);
}, "a section cannot contain another section");

expectDomainException(static function () use ($document): void {
    $downloadLink = $document;
    $downloadLink["blocks"]["text-1"]["props"]["content"]["content"][0]["content"][0]["marks"][1]["attrs"]["href"]
        = "https://example.com/file.exe";
    CmsContentValidator::validate($downloadLink, 7, 12);
}, "download links are rejected");

expectDomainException(static function () use ($document): void {
    $rawHtml = $document;
    $rawHtml["blocks"]["text-1"]["props"]["html"] = "<script>alert(1)</script>";
    CmsContentValidator::validate($rawHtml, 7, 12);
}, "raw HTML properties are rejected");

$moderatedText = $document;
$moderatedText["blocks"]["text-1"]["props"]["moderationRemoved"] = true;
$restoredWithoutChange = $moderatedText;
unset($restoredWithoutChange["blocks"]["text-1"]["props"]["moderationRemoved"]);
$encode = static fn (array $value): string => json_encode(
    $value,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);

expectDomainException(static function () use ($moderatedText, $restoredWithoutChange, $encode): void {
    CmsModerationGuard::validateReplacement($encode($moderatedText), $encode($restoredWithoutChange));
}, "moderated text cannot be restored unchanged");

$replacedText = $restoredWithoutChange;
$replacedText["blocks"]["text-1"]["props"]["content"]["content"][0]["content"][0]["text"] = "Texte remplacÃ©";
CmsModerationGuard::validateReplacement($encode($moderatedText), $encode($replacedText));

$decision = (new ReflectionClass(ModerationDecision::class))->newInstanceWithoutConstructor();
$moderatedDocument = new ReflectionMethod(ModerationDecision::class, "moderatedDocument");
$legacyMutation = $moderatedDocument->invoke($decision, $encode([
    "blocks" => [
        ["type" => "heading", "content" => "Titre historique"],
        ["type" => "paragraph", "content" => "Texte signale"],
    ],
]), "legacy-1");
$normalizedLegacy = json_decode($legacyMutation["json"], true, 512, JSON_THROW_ON_ERROR);
expectCmsTest($legacyMutation["found"] === true, "legacy moderation target is found");
expectCmsTest(($normalizedLegacy["schemaVersion"] ?? null) === 1, "legacy document is normalized");
expectCmsTest(
    ($normalizedLegacy["blocks"]["legacy-1"]["props"]["moderationRemoved"] ?? false) === true,
    "legacy target receives the moderation marker",
);
$missingLegacyMutation = $moderatedDocument->invoke($decision, $encode([
    "blocks" => [["type" => "paragraph", "content" => "Contenu restant"]],
]), "legacy-8");
expectCmsTest($missingLegacyMutation["found"] === false, "missing legacy target stays absent");

echo "CmsContentValidator smoke tests: OK\n";
