<?php

declare(strict_types=1);

final class OwnedPage
{
    public function __construct(private PDO $pdo) {}

    public function findByOwnerId(int $ownerUserId): array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, is_anonymous,
                number_of_likes, number_of_view, number_of_followers,
                page_description, page_picture, created_at, updated_at
            FROM pages
            WHERE owner_user_id = :owner_user_id
            ORDER BY updated_at DESC, id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(":owner_user_id", $ownerUserId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $page): array => $this->normalize($page),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findOwnedById(int $id, int $ownerUserId): ?array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, is_anonymous,
                number_of_likes, number_of_view, number_of_followers,
                page_description, page_picture, created_at, updated_at
            FROM pages
            WHERE id = :id AND owner_user_id = :owner_user_id
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(":id", $id, PDO::PARAM_INT);
        $stmt->bindValue(":owner_user_id", $ownerUserId, PDO::PARAM_INT);
        $stmt->execute();
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($page) ? $this->normalize($page) : null;
    }

    public function updateSettings(int $id, int $ownerUserId, string $status, bool $isAnonymous): ?array
    {
        $sql = "UPDATE pages
            SET page_status = :page_status, is_anonymous = :is_anonymous
            WHERE id = :id AND owner_user_id = :owner_user_id AND page_status != :banned_status";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(":page_status", $status, PDO::PARAM_STR);
        $stmt->bindValue(":is_anonymous", $isAnonymous, PDO::PARAM_BOOL);
        $stmt->bindValue(":id", $id, PDO::PARAM_INT);
        $stmt->bindValue(":owner_user_id", $ownerUserId, PDO::PARAM_INT);
        $stmt->bindValue(":banned_status", "banned", PDO::PARAM_STR);
        $stmt->execute();

        return $this->findOwnedById($id, $ownerUserId);
    }

    private function normalize(array $page): array
    {
        foreach (["id", "owner_user_id", "number_of_likes", "number_of_view", "number_of_followers"] as $key) {
            $page[$key] = (int) $page[$key];
        }

        $page["is_anonymous"] = (bool) $page["is_anonymous"];

        return $page;
    }
}
