<?php

declare(strict_types=1);

final class PageFilter
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByPageId(int $pageId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT page_filters.id, page_filters.page_id, page_filters.filter_id, page_filters.created_at,
                filters.filter_name, filters.filter_type, filters.belong_to
            FROM page_filters
            INNER JOIN filters ON filters.id = page_filters.filter_id
            WHERE page_filters.page_id = :page_id
            ORDER BY filters.filter_type, filters.filter_name"
        );
        $statement->bindValue(":page_id", $pageId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, page_id, filter_id, created_at
            FROM page_filters
            WHERE id = :id
            LIMIT 1"
        );
        $statement->bindValue(":id", $id, PDO::PARAM_INT);
        $statement->execute();

        $pageFilter = $statement->fetch();

        return is_array($pageFilter) ? $pageFilter : null;
    }

    public function create(int $pageId, int $filterId): array
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO page_filters (page_id, filter_id)
            VALUES (:page_id, :filter_id)"
        );
        $statement->bindValue(":page_id", $pageId, PDO::PARAM_INT);
        $statement->bindValue(":filter_id", $filterId, PDO::PARAM_INT);
        $statement->execute();

        $pageFilter = $this->findById((int) $this->pdo->lastInsertId());

        if ($pageFilter === null) {
            throw new RuntimeException("Association de filtre introuvable apres creation");
        }

        return $pageFilter;
    }

    public function update(int $id, int $pageId, int $filterId): ?array
    {
        $statement = $this->pdo->prepare(
            "UPDATE page_filters
            SET page_id = :page_id,
                filter_id = :filter_id
            WHERE id = :id"
        );
        $statement->bindValue(":id", $id, PDO::PARAM_INT);
        $statement->bindValue(":page_id", $pageId, PDO::PARAM_INT);
        $statement->bindValue(":filter_id", $filterId, PDO::PARAM_INT);
        $statement->execute();

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM page_filters
            WHERE id = :id"
        );
        $statement->bindValue(":id", $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0;
    }

    public function deleteByPageAndFilter(int $pageId, int $filterId): bool
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM page_filters
            WHERE page_id = :page_id AND filter_id = :filter_id"
        );
        $statement->bindValue(":page_id", $pageId, PDO::PARAM_INT);
        $statement->bindValue(":filter_id", $filterId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0;
    }
}
