<?php

declare(strict_types=1);

/**
 * Creates and removes authenticated likes, favorites and follows for PageEngagementController.
 * The mutation flow is frontend -> POST/DELETE /pages/{id}/{engagement} -> controller -> this model
 * -> users_engagement rows -> cached pages counters -> JSON response. Adding a favorite also adds a like,
 * while removing a like is rejected as long as the same user still has the page in favorites.
 */
final class PageEngagement
{
    public function __construct(private PDO $pdo) {}

    public function addFavorite(int $pageId, int $userId): array
    {
        return $this->setEngagement($pageId, $userId, "favorite", true);
    }

    public function removeFavorite(int $pageId, int $userId): array
    {
        return $this->setEngagement($pageId, $userId, "favorite", false);
    }

    public function addLike(int $pageId, int $userId): array
    {
        return $this->setEngagement($pageId, $userId, "like", true);
    }

    public function removeLike(int $pageId, int $userId): array
    {
        return $this->setEngagement($pageId, $userId, "like", false);
    }

    public function addFollow(int $pageId, int $userId): array
    {
        return $this->setEngagement($pageId, $userId, "follow", true);
    }

    public function removeFollow(int $pageId, int $userId): array
    {
        return $this->setEngagement($pageId, $userId, "follow", false);
    }

    private function setEngagement(int $pageId, int $userId, string $type, bool $enabled): array
    {
        $this->pdo->beginTransaction();

        try {
            $page = $this->lockPublicPage($pageId);
            $states = $this->findUserStates($pageId, $userId);

            if ($type === "like" && !$enabled && $states["favorite"]) {
                throw new DomainException("Retirez cette page des favoris avant de retirer son like");
            }

            $changes = [$type => $enabled];

            if ($type === "favorite" && $enabled) $changes["like"] = true;

            $deltas = ["like" => 0, "follow" => 0, "favorite" => 0];

            foreach ($changes as $engagementType => $newState) {
                if ($states[$engagementType] === $newState) continue;

                $this->writeEngagement($pageId, $userId, $engagementType, $newState);
                $states[$engagementType] = $newState;
                $deltas[$engagementType] = $newState ? 1 : -1;
            }

            $this->updateCounters($pageId, $deltas);
            $result = [
                "is_liked" => $states["like"],
                "is_following" => $states["follow"],
                "is_favorite" => $states["favorite"],
                "number_of_likes" => max((int) $page["number_of_likes"] + $deltas["like"], 0),
                "number_of_followers" => max((int) $page["number_of_followers"] + $deltas["follow"], 0),
                "number_of_favorites" => max((int) $page["number_of_favorites"] + $deltas["favorite"], 0),
            ];

            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function lockPublicPage(int $pageId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, number_of_likes, number_of_followers, number_of_favorites
            FROM pages
            WHERE id = :page_id AND page_status = :page_status
            FOR UPDATE");
        $stmt->execute([
            ":page_id" => $pageId,
            ":page_status" => "public",
        ]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($page)) throw new DomainException("Page introuvable");

        return $page;
    }

    private function findUserStates(int $pageId, int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT engagement_type
            FROM users_engagement
            WHERE user_id = :user_id AND page_id = :page_id");
        $stmt->execute([
            ":user_id" => $userId,
            ":page_id" => $pageId,
        ]);
        $activeTypes = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

        return [
            "like" => isset($activeTypes["like"]),
            "follow" => isset($activeTypes["follow"]),
            "favorite" => isset($activeTypes["favorite"]),
        ];
    }

    private function writeEngagement(int $pageId, int $userId, string $type, bool $enabled): void
    {
        $sql = $enabled
            ? "INSERT INTO users_engagement (user_id, page_id, engagement_type)
                VALUES (:user_id, :page_id, :engagement_type)"
            : "DELETE FROM users_engagement
                WHERE user_id = :user_id AND page_id = :page_id AND engagement_type = :engagement_type";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ":user_id" => $userId,
            ":page_id" => $pageId,
            ":engagement_type" => $type,
        ]);
    }

    private function updateCounters(int $pageId, array $deltas): void
    {
        if (!array_filter($deltas)) return;

        $stmt = $this->pdo->prepare("UPDATE pages
            SET number_of_likes = GREATEST(number_of_likes + :like_delta, 0),
                number_of_followers = GREATEST(number_of_followers + :follow_delta, 0),
                number_of_favorites = GREATEST(number_of_favorites + :favorite_delta, 0),
                updated_at = updated_at
            WHERE id = :page_id");
        $stmt->execute([
            ":like_delta" => $deltas["like"],
            ":follow_delta" => $deltas["follow"],
            ":favorite_delta" => $deltas["favorite"],
            ":page_id" => $pageId,
        ]);
    }
}
