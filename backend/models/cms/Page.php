<?php

declare(strict_types=1);

/**
 * Reads public/owned pages and updates page metadata for PageController.
 * GET /pages reaches findPublicCards(), which matches IDs through page_filters and ranks rolling-period
 * page_view_events, users_engagement or pages.updated_at before returning public cards.
 * POST /pages creates the private page row and its first page_revision draft in one SQL transaction.
 * CMS content edits no longer update pages.pagecontent directly; PageRevision copies content there on publication.
 * Report targets include pagecontent for ModerationController -> Moderation case snapshot creation.
 */
final class Page
{
    private const STATUSES = ["public", "private"];

    public function __construct(private PDO $pdo) {}

    public function findPublicCards(
        ?int $themeId = null,
        ?int $categoryId = null,
        ?int $subcategoryId = null,
        ?string $sortBy = null,
        ?string $sortOrder = null,
        ?int $viewerUserId = null,
        bool $favoritesOnly = false,
        ?string $ranking = null,
        ?string $period = null,
        ?string $feed = null
    ): array
    {
        $where = ["pages.page_status = :page_status"];
        $values = [
            ":page_status" => "public",
        ];
        $favoriteStateSelect = "0";

        if ($viewerUserId !== null) {
            $favoriteStateSelect = "EXISTS (
                SELECT 1
                FROM users_engagement viewer_favorite
                WHERE viewer_favorite.page_id = pages.id
                    AND viewer_favorite.user_id = :favorite_state_user_id
                    AND viewer_favorite.engagement_type = :favorite_state_type
            )";
            $values[":favorite_state_user_id"] = $viewerUserId;
            $values[":favorite_state_type"] = "favorite";
        }

        if ($themeId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM page_filters
                INNER JOIN filters selected_filter ON selected_filter.id = page_filters.filter_id
                WHERE page_filters.page_id = pages.id
                    AND selected_filter.id = :theme_filter_id
                    AND selected_filter.filter_type = :theme_filter_type
            )";
            $values[":theme_filter_id"] = $themeId;
            $values[":theme_filter_type"] = "theme";
        }

        if ($categoryId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM page_filters
                INNER JOIN filters selected_filter ON selected_filter.id = page_filters.filter_id
                WHERE page_filters.page_id = pages.id
                    AND selected_filter.id = :category_filter_id
                    AND selected_filter.filter_type = :category_filter_type
            )";
            $values[":category_filter_id"] = $categoryId;
            $values[":category_filter_type"] = "category";
        }

        if ($subcategoryId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM page_filters
                INNER JOIN filters selected_filter ON selected_filter.id = page_filters.filter_id
                WHERE page_filters.page_id = pages.id
                    AND selected_filter.id = :subcategory_filter_id
                    AND selected_filter.filter_type = :subcategory_filter_type
            )";
            $values[":subcategory_filter_id"] = $subcategoryId;
            $values[":subcategory_filter_type"] = "subcategory";
        }

        if ($favoritesOnly && $viewerUserId !== null) {
            $where[] = "EXISTS (
                SELECT 1
                FROM users_engagement
                WHERE users_engagement.page_id = pages.id
                    AND users_engagement.user_id = :favorite_user_id
                    AND users_engagement.engagement_type = :favorite_engagement_type
            )";
            $values[":favorite_user_id"] = $viewerUserId;
            $values[":favorite_engagement_type"] = "favorite";
        }

        $sortColumns = [
            "date" => "pages.updated_at",
            "like" => "pages.number_of_likes",
            "view" => "pages.number_of_view",
        ];
        $orderBy = "pages.id DESC";
        $joins = [];

        if ($feed === "new") {
            $where[] = "pages.created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)";
            $orderBy = "pages.created_at DESC, pages.id DESC";
        } elseif ($feed === "trending") {
            $joins[] = "LEFT JOIN (
                SELECT page_id,
                    SUM(CASE WHEN viewed_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS recent_view_score,
                    SUM(CASE WHEN viewed_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS previous_view_score
                FROM page_view_events
                WHERE viewed_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 31 DAY)
                GROUP BY page_id
            ) trend_views ON trend_views.page_id = pages.id";
            $joins[] = "LEFT JOIN (
                SELECT page_id,
                    SUM(CASE
                        WHEN created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) AND engagement_type = 'like' THEN 10
                        WHEN created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) AND engagement_type = 'favorite' THEN 15
                        ELSE 0
                    END) AS recent_engagement_score,
                    SUM(CASE
                        WHEN created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) AND engagement_type = 'like' THEN 10
                        WHEN created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) AND engagement_type = 'favorite' THEN 15
                        ELSE 0
                    END) AS previous_engagement_score
                FROM users_engagement
                WHERE created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 31 DAY)
                GROUP BY page_id
            ) trend_engagements ON trend_engagements.page_id = pages.id";
            $recentTrendScore = "(
                COALESCE(trend_views.recent_view_score, 0)
                + COALESCE(trend_engagements.recent_engagement_score, 0)
            )";
            $baselineTrendScore = "(
                COALESCE(trend_views.previous_view_score, 0)
                + COALESCE(trend_engagements.previous_engagement_score, 0)
            ) / 30";
            $where[] = $recentTrendScore . " > " . $baselineTrendScore;
            $orderBy = "(" . $recentTrendScore . " - " . $baselineTrendScore . ") DESC, pages.id DESC";
        } elseif ($feed !== null) {
            throw new InvalidArgumentException("Mode de decouverte invalide");
        }

        if ($ranking !== null || $period !== null) {
            $periodStart = $this->rankingPeriodStart($period);

            if ($ranking === "popular") {
                $joins[] = "LEFT JOIN (
                    SELECT page_id, COUNT(*) AS period_view_count
                    FROM page_view_events
                    WHERE viewed_at >= " . $periodStart . "
                    GROUP BY page_id
                ) period_views ON period_views.page_id = pages.id";
                $joins[] = "LEFT JOIN (
                    SELECT page_id, COUNT(*) AS period_like_count
                    FROM users_engagement
                    WHERE engagement_type = :ranking_like_type
                        AND created_at >= " . $periodStart . "
                    GROUP BY page_id
                ) period_likes ON period_likes.page_id = pages.id";
                $values[":ranking_like_type"] = "like";
                $orderBy = "(
                    COALESCE(period_views.period_view_count, 0)
                    + COALESCE(period_likes.period_like_count, 0) * 10
                ) DESC, pages.id DESC";
            } elseif ($ranking === "favorites") {
                $joins[] = "LEFT JOIN (
                    SELECT page_id, COUNT(*) AS period_favorite_count
                    FROM users_engagement
                    WHERE engagement_type = :ranking_favorite_type
                        AND created_at >= " . $periodStart . "
                    GROUP BY page_id
                ) period_favorites ON period_favorites.page_id = pages.id";
                $values[":ranking_favorite_type"] = "favorite";
                $orderBy = "COALESCE(period_favorites.period_favorite_count, 0) DESC, pages.id DESC";
            } elseif ($ranking === "updated") {
                $where[] = "pages.updated_at >= " . $periodStart;
                $orderBy = "pages.updated_at DESC, pages.id DESC";
            } else {
                throw new InvalidArgumentException("Classement de pages invalide");
            }
        }

        if ($sortBy !== null || $sortOrder !== null) {
            if (!isset($sortColumns[$sortBy]) || !in_array($sortOrder, ["asc", "desc"], true)) {
                throw new InvalidArgumentException("Tri de pages invalide");
            }

            $direction = strtoupper($sortOrder);
            $orderBy = $sortColumns[$sortBy] . " " . $direction . ", pages.id " . $direction;
        }

        $sql = "SELECT pages.id,
                CASE WHEN pages.is_anonymous = 1 THEN NULL ELSE pages.owner_user_id END AS owner_user_id,
                CASE WHEN pages.is_anonymous = 1 THEN NULL ELSE users.username END AS owner_username,
                CASE WHEN pages.is_anonymous = 1 THEN NULL ELSE users.profil_picture END AS owner_picture,
                pages.page_title, pages.page_status, pages.is_anonymous, pages.number_of_likes,
                pages.number_of_view, pages.number_of_followers, pages.number_of_favorites,
                " . $favoriteStateSelect . " AS is_favorite,
                pages.page_description, pages.page_picture, pages.created_at, pages.updated_at
            FROM pages
            INNER JOIN users ON users.id = pages.owner_user_id
            " . implode("\n", $joins) . "
            WHERE " . implode(" AND ", $where) . "
            ORDER BY " . $orderBy;

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, $values);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rankingPeriodStart(?string $period): string
    {
        $periods = [
            "24h" => "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR)",
            "7d" => "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)",
            "1month" => "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 MONTH)",
            "3month" => "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 MONTH)",
            "6month" => "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 MONTH)",
            "1year" => "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 YEAR)",
        ];

        if ($period === null || !isset($periods[$period])) {
            throw new InvalidArgumentException("Periode de classement invalide");
        }

        return $periods[$period];
    }

    public function findCardsByOwnerId(int $ownerUserId): array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, is_anonymous, number_of_likes,
                number_of_view, number_of_followers, page_description, page_picture,
                created_at, updated_at
            FROM pages
            WHERE owner_user_id = :owner_user_id
            ORDER BY updated_at DESC, id DESC";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":owner_user_id" => $ownerUserId,
        ]);
        $stmt->execute();

        return array_map(
            fn (array $page): array => $this->normalizeOwnedPage($page),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function findPublicReportTarget(int $id): ?array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, page_picture, pagecontent
            FROM pages
            WHERE id = :id AND page_status = :page_status
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":page_status" => "public",
        ]);
        $stmt->execute();
        return $this->fetchPageWithContent($stmt);
    }

    public function findContentById(int $id): ?array
    {
        $sql = "SELECT id, owner_user_id, page_title, page_status, is_anonymous, page_picture, pagecontent
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
        $sql = "SELECT id, owner_user_id, page_title, page_status, is_anonymous, number_of_likes,
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
        $sql = "SELECT id, owner_user_id, page_title, page_status, is_anonymous, pagecontent
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

    public function create(int $ownerUserId, string $title, string $initialContent): array
    {
        $this->pdo->beginTransaction();

        try {
            $sql = "INSERT INTO pages (owner_user_id, page_title, pagecontent)
                VALUES (:owner_user_id, :page_title, :pagecontent)";
            $stmt = $this->pdo->prepare($sql);
            $this->bindValues($stmt, [
                ":owner_user_id" => $ownerUserId,
                ":page_title" => $title,
                ":pagecontent" => $initialContent,
            ]);
            $stmt->execute();
            $pageId = (int) $this->pdo->lastInsertId();

            $revision = $this->pdo->prepare("INSERT INTO page_revision (
                    page_id, created_by_user_id, revision_number, revision_status, is_current,
                    current_draft_page_id, current_published_page_id, pagecontent
                ) VALUES (
                    :page_id, :created_by_user_id, 1, :revision_status, TRUE,
                    :current_draft_page_id, NULL, :pagecontent
                )");
            $revision->execute([
                ":page_id" => $pageId,
                ":created_by_user_id" => $ownerUserId,
                ":revision_status" => "draft",
                ":current_draft_page_id" => $pageId,
                ":pagecontent" => $initialContent,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }

        $page = $this->findOwnedById($pageId, $ownerUserId);

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

    public function updateSettings(int $id, int $ownerUserId, string $status, bool $isAnonymous): ?array
    {
        $sql = "UPDATE pages
            SET page_status = :page_status, is_anonymous = :is_anonymous
            WHERE id = :id AND owner_user_id = :owner_user_id AND page_status != :banned_status";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":page_status" => $status,
            ":is_anonymous" => $isAnonymous,
            ":id" => $id,
            ":owner_user_id" => $ownerUserId,
            ":banned_status" => "banned",
        ]);
        $stmt->execute();
        $page = $this->findOwnedById($id, $ownerUserId);

        return $page === null ? null : $this->normalizeOwnedPage($page);
    }

    private function normalizeOwnedPage(array $page): array
    {
        foreach (["id", "owner_user_id", "number_of_likes", "number_of_view", "number_of_followers"] as $key) {
            $page[$key] = (int) $page[$key];
        }

        $page["is_anonymous"] = (bool) $page["is_anonymous"];

        return $page;
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
