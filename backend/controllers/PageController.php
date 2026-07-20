<?php

declare(strict_types=1);

final class PageController
{
    private const TITLE_MAX_LENGTH = 255;

    private Page $pages;

    public function __construct(PDO $pdo)
    {
        $this->pages = new Page($pdo);
    }

    public function index(): void
    {
        $themeId = $this->optionalPositiveIntQuery("theme_id");
        $categoryId = $this->optionalPositiveIntQuery("category_id");
        $subcategoryId = $this->optionalPositiveIntQuery("subcategory_id");
        $sortBy = $this->optionalEnumQuery("sort_by", ["date", "like", "view"]);
        $sortOrder = $this->optionalEnumQuery("sort_order", ["asc", "desc"]);

        if (($sortBy === null) !== ($sortOrder === null)) {
            Response::error("Le type et l'ordre du tri sont requis ensemble", 422);
        }

        $this->validateFavoriteQuery();

        Response::json(200, [
            "pages" => $this->pages->findPublicCards(
                $themeId,
                $categoryId,
                $subcategoryId,
                $sortBy,
                $sortOrder
            ),
        ]);
    }

    public function mine(): void
    {
        $userId = $this->authenticatedUserId();

        Response::json(200, [
            "pages" => $this->pages->findCardsByOwnerId($userId),
        ]);
    }

    public function show(int $id): void
    {
        $page = $this->pages->findContentById($id);

        if ($page === null || $page["page_status"] === "banned") {
            Response::error("Page introuvable", 404);
        }

        $userId = Session::userId();

        if ($page["page_status"] === "private" && $userId !== (int) $page["owner_user_id"]) {
            Response::error("Page introuvable", 404);
        }

        if ((bool) $page["is_anonymous"] && $userId !== (int) $page["owner_user_id"]) {
            $page["owner_user_id"] = null;
        }

        Response::json(200, [
            "page" => $page,
        ]);
    }

    public function create(): void
    {
        $userId = $this->authenticatedUserId();
        $body = Request::body();
        $title = Request::field($body, ["page_title", "title"]);

        $this->validateTitle($title);

        Response::json(201, [
            "message" => "Page creee",
            "page" => $this->pages->create($userId, $title),
        ]);
    }

    public function updateTitle(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $this->requireOwnedPage($id, $userId);
        $body = Request::body();
        $title = Request::field($body, ["page_title", "title"]);

        $this->validateTitle($title);
        $page = $this->pages->updateTitle($id, $userId, $title);

        Response::json(200, [
            "message" => "Titre mis a jour",
            "page" => $page,
        ]);
    }

    public function updateDescription(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $this->requireOwnedPage($id, $userId);
        $body = Request::body();
        $description = $this->nullableStringField($body, "page_description");
        $page = $this->pages->updateDescription($id, $userId, $description);

        Response::json(200, [
            "message" => "Description mise a jour",
            "page" => $page,
        ]);
    }

    public function updatePicture(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $this->requireOwnedPage($id, $userId);
        $body = Request::body();
        $picture = $this->nullableStringField($body, "page_picture");
        $page = $this->pages->updatePicture($id, $userId, $picture);

        Response::json(200, [
            "message" => "Image mise a jour",
            "page" => $page,
        ]);
    }

    public function updateStatus(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $ownedPage = $this->pages->findOwnedById($id, $userId);

        if ($ownedPage === null) {
            Response::error("Page introuvable", 404);
        }

        if ($ownedPage["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas etre modifiee", 403);
        }

        $body = Request::body();
        $status = Request::field($body, ["page_status", "status"]);

        if ($status === "") {
            Response::error("Statut de page requis", 422);
        }

        try {
            $page = $this->pages->updateStatus($id, $userId, $status);
        } catch (InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422);
        }

        Response::json(200, [
            "message" => "Statut mis a jour",
            "page" => $page,
        ]);
    }

    public function updateContent(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $this->requireOwnedPage($id, $userId);
        $body = Request::body();

        if (!array_key_exists("pagecontent", $body) || !is_array($body["pagecontent"])) {
            Response::error("Contenu JSON de la page requis", 422);
        }

        $contentValue = $body["pagecontent"] === [] ? new stdClass() : $body["pagecontent"];
        $content = json_encode($contentValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $page = $this->pages->updateContent($id, $userId, $content);

        Response::json(200, [
            "message" => "Contenu mis a jour",
            "page" => $page,
        ]);
    }

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Non authentifie", 401);
        }

        return $userId;
    }

    private function requireOwnedPage(int $id, int $userId): void
    {
        if ($this->pages->findOwnedById($id, $userId) === null) {
            Response::error("Page introuvable", 404);
        }
    }

    private function validateTitle(string $title): void
    {
        if ($title === "") {
            Response::error("Titre de page requis", 422);
        }

        if (strlen($title) > self::TITLE_MAX_LENGTH) {
            Response::error("Titre de page trop long", 422);
        }
    }

    private function nullableStringField(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) {
            Response::error("Champ " . $key . " requis", 422);
        }

        if ($body[$key] === null) {
            return null;
        }

        if (!is_scalar($body[$key])) {
            Response::error("Champ " . $key . " invalide", 422);
        }

        $value = trim((string) $body[$key]);

        return $value === "" ? null : $value;
    }

    private function optionalPositiveIntQuery(string $key): ?int
    {
        if (!array_key_exists($key, $_GET) || $_GET[$key] === "") {
            return null;
        }

        if (!is_scalar($_GET[$key])) {
            Response::error("Parametre " . $key . " invalide", 422);
        }

        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1],
        ]);

        if ($value === false) {
            Response::error("Parametre " . $key . " invalide", 422);
        }

        return (int) $value;
    }

    private function optionalEnumQuery(string $key, array $allowedValues): ?string
    {
        if (!array_key_exists($key, $_GET) || $_GET[$key] === "") {
            return null;
        }

        if (!is_scalar($_GET[$key])) {
            Response::error("Parametre " . $key . " invalide", 422);
        }

        $value = strtolower(trim((string) $_GET[$key]));

        if (!in_array($value, $allowedValues, true)) {
            Response::error("Parametre " . $key . " invalide", 422);
        }

        return $value;
    }

    private function validateFavoriteQuery(): void
    {
        if (!array_key_exists("is_favorite", $_GET) || $_GET["is_favorite"] === "") {
            return;
        }

        if (!is_scalar($_GET["is_favorite"]) || !in_array((string) $_GET["is_favorite"], ["0", "1"], true)) {
            Response::error("Parametre is_favorite invalide", 422);
        }
    }
}
