<?php

declare(strict_types=1);

/**
 * Stores and publishes CMS revisions for the authenticated page owner.
 * PageController calls this model from GET/PUT /pages/{id}/draft and POST /pages/{id}/publish.
 * Draft JSON stays in page_revision; CmsModerationGuard requires actual replacement of marked blocks before
 * their marker is removed, then publication promotes the draft and copies it to pages.pagecontent.
 */
final class PageRevision
{
    public function __construct(private PDO $pdo) {}

    public function getOrCreateDraft(int $pageId, int $ownerUserId): array
    {
        return $this->transaction(function () use ($pageId, $ownerUserId): array {
            $page = $this->lockOwnedPage($pageId, $ownerUserId);
            $draft = $this->findCurrentDraftForUpdate($pageId);

            if ($draft === null) {
                $draft = $this->insertDraft($pageId, $ownerUserId, (string) $page["pagecontent"]);
            }

            return $this->normalizeRevision($draft);
        });
    }

    public function saveDraft(int $pageId, int $ownerUserId, string $pagecontent): array
    {
        return $this->transaction(function () use ($pageId, $ownerUserId, $pagecontent): array {
            $page = $this->lockOwnedPage($pageId, $ownerUserId);
            $draft = $this->findCurrentDraftForUpdate($pageId);
            CmsModerationGuard::validateReplacement(
                (string) ($draft["pagecontent"] ?? $page["pagecontent"]),
                $pagecontent,
            );

            if ($draft === null) {
                return $this->normalizeRevision($this->insertDraft($pageId, $ownerUserId, $pagecontent));
            }

            $stmt = $this->pdo->prepare("UPDATE page_revision
                SET pagecontent = :pagecontent, created_by_user_id = :created_by_user_id
                WHERE id = :id AND current_draft_page_id = :page_id");
            $stmt->execute([
                ":pagecontent" => $pagecontent,
                ":created_by_user_id" => $ownerUserId,
                ":id" => (int) $draft["id"],
                ":page_id" => $pageId,
            ]);

            return $this->normalizeRevision($this->findRevisionById((int) $draft["id"]));
        });
    }

    public function publishDraft(int $pageId, int $ownerUserId): array
    {
        return $this->transaction(function () use ($pageId, $ownerUserId): array {
            $this->lockOwnedPage($pageId, $ownerUserId);
            $draft = $this->findCurrentDraftForUpdate($pageId);

            if ($draft === null) {
                throw new DomainException("Aucun brouillon a publier");
            }

            $document = json_decode((string) $draft["pagecontent"], true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($document)) {
                throw new DomainException("Contenu du brouillon invalide");
            }

            CmsContentValidator::validate($document, $ownerUserId, $pageId);

            $archive = $this->pdo->prepare("UPDATE page_revision
                SET revision_status = :archived_status, is_current = FALSE, current_published_page_id = NULL
                WHERE page_id = :page_id AND current_published_page_id = :current_page_id");
            $archive->execute([
                ":archived_status" => "archived",
                ":page_id" => $pageId,
                ":current_page_id" => $pageId,
            ]);

            $promote = $this->pdo->prepare("UPDATE page_revision
                SET revision_status = :published_status,
                    current_draft_page_id = NULL,
                    current_published_page_id = :current_page_id,
                    published_at = CURRENT_TIMESTAMP
                WHERE id = :id AND current_draft_page_id = :page_id");
            $promote->execute([
                ":published_status" => "published",
                ":current_page_id" => $pageId,
                ":id" => (int) $draft["id"],
                ":page_id" => $pageId,
            ]);

            $publishPage = $this->pdo->prepare("UPDATE pages
                SET pagecontent = :pagecontent, page_status = :page_status
                WHERE id = :id AND owner_user_id = :owner_user_id");
            $publishPage->execute([
                ":pagecontent" => (string) $draft["pagecontent"],
                ":page_status" => "private",
                ":id" => $pageId,
                ":owner_user_id" => $ownerUserId,
            ]);

            return $this->normalizeRevision($this->findRevisionById((int) $draft["id"]));
        });
    }

    private function insertDraft(int $pageId, int $ownerUserId, string $pagecontent): array
    {
        $revisionNumber = $this->nextRevisionNumber($pageId);
        $stmt = $this->pdo->prepare("INSERT INTO page_revision (
                page_id, created_by_user_id, revision_number, revision_status, is_current,
                current_draft_page_id, current_published_page_id, pagecontent
            ) VALUES (
                :page_id, :created_by_user_id, :revision_number, :revision_status, TRUE,
                :current_draft_page_id, NULL, :pagecontent
            )");
        $stmt->execute([
            ":page_id" => $pageId,
            ":created_by_user_id" => $ownerUserId,
            ":revision_number" => $revisionNumber,
            ":revision_status" => "draft",
            ":current_draft_page_id" => $pageId,
            ":pagecontent" => $pagecontent,
        ]);

        return $this->findRevisionById((int) $this->pdo->lastInsertId());
    }

    private function lockOwnedPage(int $pageId, int $ownerUserId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, page_status, pagecontent
            FROM pages
            WHERE id = :id AND owner_user_id = :owner_user_id
            LIMIT 1
            FOR UPDATE");
        $stmt->execute([
            ":id" => $pageId,
            ":owner_user_id" => $ownerUserId,
        ]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($page)) {
            throw new DomainException("Page introuvable");
        }

        if ($page["page_status"] === "banned") {
            throw new DomainException("Une page bannie ne peut pas etre modifiee");
        }

        return $page;
    }

    private function findCurrentDraftForUpdate(int $pageId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT *
            FROM page_revision
            WHERE page_id = :page_id AND current_draft_page_id = :current_page_id
            LIMIT 1
            FOR UPDATE");
        $stmt->execute([
            ":page_id" => $pageId,
            ":current_page_id" => $pageId,
        ]);
        $revision = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($revision) ? $revision : null;
    }

    private function findRevisionById(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM page_revision WHERE id = :id LIMIT 1");
        $stmt->execute([":id" => $id]);
        $revision = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($revision)) {
            throw new RuntimeException("Revision introuvable");
        }

        return $revision;
    }

    private function nextRevisionNumber(int $pageId): int
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(revision_number), 0) + 1
            FROM page_revision
            WHERE page_id = :page_id");
        $stmt->execute([":page_id" => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    private function normalizeRevision(array $revision): array
    {
        $revision["id"] = (int) $revision["id"];
        $revision["page_id"] = (int) $revision["page_id"];
        $revision["revision_number"] = (int) $revision["revision_number"];
        $revision["is_current"] = (bool) $revision["is_current"];
        $revision["pagecontent"] = json_decode((string) $revision["pagecontent"], false, 512, JSON_THROW_ON_ERROR);
        unset($revision["current_draft_page_id"], $revision["current_published_page_id"]);

        return $revision;
    }

    private function transaction(callable $operation): array
    {
        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }
    }
}
