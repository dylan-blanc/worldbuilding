<?php

declare(strict_types=1);

final class Page
{
    private const STATUSES = ["public", "private", "anonymous", "banned"];

    public function __construct(private PDO $pdo) {}

    public function findPublicCards(
        ?int $themeId = null,
        ?int $categoryId = null,
        ?int $subcategoryId = null,
        ?string $sortBy = null,
        ?string $sortOrder = null
    ): array
    {
        $where = ["pages.page_status = :page_status"];
        $values = [
            ":page_status" => "public",
        ];

        if ($themeId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM page_filters
                INNER JOIN filters assigned_filter ON assigned_filter.id = page_filters.filter_id
                LEFT JOIN filters assigned_parent ON assigned_parent.id = assigned_filter.belong_to
                WHERE page_filters.page_id = pages.id
                    AND (
                        assigned_filter.id = :theme_filter_id
                        OR assigned_filter.belong_to = :theme_child_id
                        OR assigned_parent.belong_to = :theme_descendant_id
                    )
            )";
            $values[":theme_filter_id"] = $themeId;
            $values[":theme_child_id"] = $themeId;
            $values[":theme_descendant_id"] = $themeId;
        }

        if ($categoryId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM page_filters
                INNER JOIN filters assigned_filter ON assigned_filter.id = page_filters.filter_id
                WHERE page_filters.page_id = pages.id
                    AND (
                        assigned_filter.id = :category_filter_id
                        OR assigned_filter.belong_to = :category_child_id
                    )
            )";
            $values[":category_filter_id"] = $categoryId;
            $values[":category_child_id"] = $categoryId;
        }

        if ($subcategoryId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM page_filters
                WHERE page_filters.page_id = pages.id
                    AND page_filters.filter_id = :subcategory_filter_id
            )";
            $values[":subcategory_filter_id"] = $subcategoryId;
        }

        $sortColumns = [
            "date" => "pages.updated_at",
            "like" => "pages.number_of_likes",
            "view" => "pages.number_of_view",
        ];
        $orderBy = "pages.id DESC";

        if ($sortBy !== null || $sortOrder !== null) {
            if (!isset($sortColumns[$sortBy]) || !in_array($sortOrder, ["asc", "desc"], true)) {
                throw new InvalidArgumentException("Tri de pages invalide");
            }

            $direction = strtoupper($sortOrder);
            $orderBy = $sortColumns[$sortBy] . " " . $direction . ", pages.id " . $direction;
        }

        $sql = "SELECT pages.id, pages.owner_user_id, users.username AS owner_username,
                pages.page_title, pages.page_status, pages.number_of_likes,
                pages.number_of_view, pages.number_of_followers, pages.page_description,
                pages.page_picture, pages.created_at, pages.updated_at
            FROM pages
            INNER JOIN users ON users.id = pages.owner_user_id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY " . $orderBy;

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, $values);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCardsByOwnerId(int $ownerUserId): array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, number_of_likes,
                number_of_view, number_of_followers, page_description, page_picture,
                created_at, updated_at
            FROM pages
            WHERE owner_user_id = :owner_user_id
            ORDER BY created_at DESC, id DESC";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":owner_user_id" => $ownerUserId,
        ]);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findContentById(int $id): ?array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, pagecontent
            FROM pages
            WHERE id = :id
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
        ]);
        $stmt->execute();

        return $this->fetchPageWithContent($stmt);
    }

    public function findOwnedById(int $id, int $ownerUserId): ?array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, number_of_likes,
                number_of_view, number_of_followers, page_description, page_picture,
                created_at, updated_at
            FROM pages
            WHERE id = :id AND owner_user_id = :owner_user_id
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
        ]);
        $stmt->execute();

        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($page) ? $page : null;
    }

    public function findOwnedContentById(int $id, int $ownerUserId): ?array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, pagecontent
            FROM pages
            WHERE id = :id AND owner_user_id = :owner_user_id
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
        ]);
        $stmt->execute();

        return $this->fetchPageWithContent($stmt);
    }

    public function create(int $ownerUserId, string $title): array
    {
        $sql = "INSERT INTO pages (owner_user_id, page_title, pagecontent)
            VALUES (:owner_user_id, :page_title, :pagecontent)";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":owner_user_id" => $ownerUserId,
            ":page_title" => $title,
            ":pagecontent" => "{}",
        ]);
        $stmt->execute();

        $page = $this->findOwnedById((int) $this->pdo->lastInsertId(), $ownerUserId);

        if ($page === null) {
            throw new RuntimeException("Page introuvable apres creation");
        }

        return $page;
    }

    public function updateTitle(int $id, int $ownerUserId, string $title): ?array
    {
        $sql = "UPDATE pages
            SET page_title = :page_title
            WHERE id = :id AND owner_user_id = :owner_user_id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
            ":page_title" => $title,
        ]);
        $stmt->execute();

        return $this->findOwnedById($id, $ownerUserId);
    }

    public function updateDescription(int $id, int $ownerUserId, ?string $description): ?array
    {
        $sql = "UPDATE pages
            SET page_description = :page_description
            WHERE id = :id AND owner_user_id = :owner_user_id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
            ":page_description" => $description,
        ]);
        $stmt->execute();

        return $this->findOwnedById($id, $ownerUserId);
    }

    public function updatePicture(int $id, int $ownerUserId, ?string $picture): ?array
    {
        $sql = "UPDATE pages
            SET page_picture = :page_picture
            WHERE id = :id AND owner_user_id = :owner_user_id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
            ":page_picture" => $picture,
        ]);
        $stmt->execute();

        return $this->findOwnedById($id, $ownerUserId);
    }

    public function updateStatus(int $id, int $ownerUserId, string $status): ?array
    {
        $this->validateStatus($status);

        $sql = "UPDATE pages
            SET page_status = :page_status
            WHERE id = :id AND owner_user_id = :owner_user_id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
            ":page_status" => $status,
        ]);
        $stmt->execute();

        return $this->findOwnedById($id, $ownerUserId);
    }

    public function updateContent(int $id, int $ownerUserId, string $content): ?array
    {
        $sql = "UPDATE pages
            SET pagecontent = :pagecontent
            WHERE id = :id AND owner_user_id = :owner_user_id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
            ":pagecontent" => $content,
        ]);
        $stmt->execute();

        return $this->findOwnedContentById($id, $ownerUserId);
    }

    private function fetchPageWithContent(PDOStatement $stmt): ?array
    {
        $page = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($page)) {
            return null;
        }

        $page["pagecontent"] = json_decode((string) $page["pagecontent"], false, 512, JSON_THROW_ON_ERROR);

        return $page;
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Statut de page invalide");
        }
    }

    private function bindValues(PDOStatement $stmt, array $values): void
    {
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value, $this->pdoParamType($value));
        }
    }

    private function pdoParamType(mixed $value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        return PDO::PARAM_STR;
    }
}
