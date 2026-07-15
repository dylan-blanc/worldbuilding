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
        Response::json(200, [
            "pages" => $this->pages->findPublicCards(),
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

        if ($page === null || in_array($page["page_status"], ["anonymous", "banned"], true)) {
            Response::error("Page introuvable", 404);
        }

        $userId = Session::userId();

        if ($page["page_status"] === "private" && $userId !== (int) $page["owner_user_id"]) {
            Response::error("Page introuvable", 404);
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
        $this->requireOwnedPage($id, $userId);
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
}
