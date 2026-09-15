<?php

declare(strict_types=1);

/**
 * Creates and removes authenticated page favorites for PageEngagementController.
 * The mutation flow is frontend -> POST/DELETE /pages/{id}/favorite -> controller -> this model
 * -> users_engagement row -> pages.number_of_favorites counter -> JSON response.
 */
final class PageEngagement
{
    public function __construct(private PDO $pdo) {}

    public function addFavorite(int $pageId, int $userId): int
    {
        return $this->setFavorite($pageId, $userId, true);
    }

    public function removeFavorite(int $pageId, int $userId): int
    {
        return $this->setFavorite($pageId, $userId, false);
    }

    private function setFavorite(int $pageId, int $userId, bool $favorite): int
    {
        $this->pdo->beginTransaction();

        try {
            $page = $this->pdo->prepare("SELECT id
                FROM pages
                WHERE id = :page_id AND page_status = :page_status
                FOR UPDATE");
            $page->execute([
                ":page_id" => $pageId,
                ":page_status" => "public",
            ]);

            if ($page->fetchColumn() === false) {
                throw new DomainException("Page introuvable");
            }

            $engagement = $favorite
                ? $this->pdo->prepare("INSERT INTO users_engagement (user_id, page_id, engagement_type)
                    VALUES (:user_id, :page_id, :engagement_type)
                    ON DUPLICATE KEY UPDATE id = id")
                : $this->pdo->prepare("DELETE FROM users_engagement
                    WHERE user_id = :user_id AND page_id = :page_id AND engagement_type = :engagement_type");
            $engagement->execute([
                ":user_id" => $userId,
                ":page_id" => $pageId,
                ":engagement_type" => "favorite",
            ]);

            if ($engagement->rowCount() > 0) {
                $operator = $favorite ? "+ 1" : "- 1";
                $counter = $this->pdo->prepare("UPDATE pages
                    SET number_of_favorites = GREATEST(number_of_favorites " . $operator . ", 0),
                        updated_at = updated_at
                    WHERE id = :page_id");
                $counter->execute([
                    ":page_id" => $pageId,
                ]);
            }

            $count = $this->pdo->prepare("SELECT number_of_favorites FROM pages WHERE id = :page_id");
            $count->execute([
                ":page_id" => $pageId,
            ]);
            $favoriteCount = (int) $count->fetchColumn();
            $this->pdo->commit();

            return $favoriteCount;
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }
    }
}
