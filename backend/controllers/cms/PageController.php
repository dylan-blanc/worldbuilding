<?php

declare(strict_types=1);

/**
 * Handles page metadata, CMS drafts and publication for routes declared in routes/cms/pages.php.
 * GET /me/pages and POST /me/pages/{id}/settings read and update the authenticated owner's page cards and filters.
 * GET /pages/{id} reads pages.pagecontent and applies PageReadAccess using the SQL user role.
 * PUT /pages/{id}/draft validates frontend JSON then calls PageRevision, which writes page_revision through PDO.
 * POST /pages/{id}/publish validates metadata and remote links, then promotes JSON and publishes the page.
 * PUT /pages/{id}/metadata writes the owner-selected title and page_filters without changing CMS JSON.
 */
final class PageController
{
    private PDO $pdo;
    private Page $pages;
    private PageRevision $revisions;
    private PageFilter $pageFilters;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pages = new Page($pdo);
        $this->revisions = new PageRevision($pdo);
        $this->pageFilters = new PageFilter($pdo);
        $this->users = new User($pdo);
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
        $pages = $this->pages->findCardsByOwnerId($userId);

        foreach ($pages as &$page) {
            $page["filters"] = $this->pageFilters->findByPageId((int) $page["id"]);
        }

        unset($page);

        Response::json(200, [
            "pages" => $pages,
        ]);
    }

    public function updateSettings(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $page = $this->pages->findOwnedById($id, $userId);

        if ($page === null) {
            Response::error("Page introuvable", 404);
        }

        if ($page["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas etre modifiee", 403);
        }

        $body = Request::body();
        $status = Request::field($body, ["page_status"]);

        if (!in_array($status, ["public", "private"], true)) {
            Response::error("Statut de page invalide", 422);
        }

        if (!array_key_exists("is_anonymous", $body) || !is_bool($body["is_anonymous"])) {
            Response::error("Parametre is_anonymous invalide", 422);
        }

        try {
            $title = PageMetadataValidator::title($body["page_title"] ?? null);
            $filterIds = PageMetadataValidator::filterIds($body["filter_ids"] ?? null);
            $this->pdo->beginTransaction();
            $this->pages->updateTitle($id, $userId, $title);
            $filters = $this->pageFilters->replaceForPage($id, $filterIds);
            $updatedPage = $this->pages->updateSettings($id, $userId, $status, $body["is_anonymous"]);
            $this->pdo->commit();
        } catch (DomainException $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            Response::error($exception->getMessage(), 422, "invalid_page_metadata");
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }

        if ($updatedPage === null) {
            Response::error("Page introuvable", 404);
        }

        $updatedPage["filters"] = $filters;

        Response::json(200, [
            "message" => "Parametres de page mis a jour",
            "page" => $updatedPage,
        ]);
    }

    public function show(int $id): void
    {
        $page = $this->pages->findContentById($id);

        if ($page === null) {
            Response::error("Page introuvable", 404);
        }

        $userId = Session::userId();
        $isAdmin = $userId !== null && $this->users->isAdmin($userId);

        if (!PageReadAccess::allows($page, $userId, $isAdmin)) {
            Response::error("Page introuvable", 404);
        }

        // Select the badge before anonymous responses remove the public owner identifier.
        $page["status_badge"] = PageReadAccess::statusBadge($page, $userId, $isAdmin);

        if ((bool) $page["is_anonymous"] && $userId !== (int) $page["owner_user_id"]) {
            $page["owner_user_id"] = null;
        }

        Response::json(200, [
            "page" => $page,
        ]);
    }

    public function create(): void
    {
        Request::requireSameOrigin();
        $userId = $this->authenticatedUserId();
        $body = Request::body();
        $title = $this->validatedTitle(Request::field($body, ["page_title", "title"]));

        $initialContent = json_encode(
            CmsContentValidator::emptyDocument(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        Response::json(201, [
            "message" => "Page creee",
            "page" => $this->pages->create($userId, $title, $initialContent),
        ]);
    }

    public function showDraft(int $id): void
    {
        $userId = $this->authenticatedUserId();

        try {
            $revision = $this->revisions->getOrCreateDraft($id, $userId);
        } catch (DomainException $exception) {
            $this->revisionError($exception);
        }

        Response::json(200, [
            "revision" => $revision,
            "page_title" => (string) $this->pages->findOwnedById($id, $userId)["page_title"],
            "filters" => $this->pageFilters->findByPageId($id),
        ]);
    }

    public function saveDraft(int $id): void
    {
        Request::requireSameOrigin();
        $userId = $this->authenticatedUserId();
        $this->requireOwnedPage($id, $userId);
        $body = Request::body();

        if (!array_key_exists("pagecontent", $body) || !is_array($body["pagecontent"])) {
            Response::error("Contenu JSON de la page requis", 422, "invalid_cms_document");
        }

        try {
            $document = CmsContentValidator::validate($body["pagecontent"], $userId, $id);
            $content = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $revision = $this->revisions->saveDraft($id, $userId, $content);
        } catch (DomainException $exception) {
            $this->revisionError($exception, "invalid_cms_document");
        }

        Response::json(200, [
            "message" => "Brouillon enregistre",
            "revision" => $revision,
        ]);
    }

    public function publish(int $id): void
    {
        Request::requireSameOrigin();
        $userId = $this->authenticatedUserId();
        $body = Request::body();

        if (!array_key_exists("is_anonymous", $body) || !is_bool($body["is_anonymous"])) {
            Response::error("Parametre is_anonymous invalide", 422, "invalid_publication_visibility");
        }

        $isAnonymous = $body["is_anonymous"];

        try {
            $title = PageMetadataValidator::title($body["page_title"] ?? null);
            $filterIds = PageMetadataValidator::filterIds($body["filter_ids"] ?? null);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 422, "invalid_page_metadata");
        }

        try {
            $revision = $this->revisions->publishDraft($id, $userId, $isAnonymous, $title, $filterIds);
        } catch (DomainException $exception) {
            $this->revisionError($exception, "draft_publication_failed");
        }

        Response::json(200, [
            "message" => $isAnonymous ? "Page publiee anonymement" : "Page publiee publiquement",
            "page_status" => "public",
            "is_anonymous" => $isAnonymous,
            "revision" => $revision,
        ]);
    }

    public function updateTitle(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $this->requireMutableOwnedPage($id, $userId);
        $body = Request::body();
        $title = $this->validatedTitle(Request::field($body, ["page_title", "title"]));
        $page = $this->pages->updateTitle($id, $userId, $title);

        Response::json(200, [
            "message" => "Titre mis a jour",
            "page" => $page,
        ]);
    }

    public function updateMetadata(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $this->requireMutableOwnedPage($id, $userId);
        $body = Request::body();

        try {
            $title = PageMetadataValidator::title($body["page_title"] ?? null);
            $filterIds = PageMetadataValidator::filterIds($body["filter_ids"] ?? null);
            $this->pdo->beginTransaction();
            $page = $this->pages->updateTitle($id, $userId, $title);
            $filters = $this->pageFilters->replaceForPage($id, $filterIds);
            $this->pdo->commit();
        } catch (DomainException $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            Response::error($exception->getMessage(), 422, "invalid_page_metadata");
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }

        Response::json(200, [
            "message" => "Metadonnees mises a jour",
            "page" => $page,
            "filters" => $filters,
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

        if ($picture !== null && !MediaUploadValidator::isOwnedPagePictureKey($picture, $userId, $id)) {
            Response::error("Cle d'image de presentation invalide", 422, "invalid_page_picture_key");
        }

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

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Non authentifie", 401, "authentication_required");
        }

        Session::renewForMutation();

        return $userId;
    }

    private function requireOwnedPage(int $id, int $userId): void
    {
        if ($this->pages->findOwnedById($id, $userId) === null) {
            Response::error("Page introuvable", 404);
        }
    }

    private function revisionError(DomainException $exception, string $code = "draft_error"): void
    {
        $message = $exception->getMessage();
        $status = $message === "Page introuvable" ? 404 : ($message === "Une page bannie ne peut pas etre modifiee" ? 403 : 422);

        Response::error($message, $status, $code);
    }

    private function validatedTitle(mixed $title): string
    {
        try {
            return PageMetadataValidator::title($title);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 422, "invalid_page_title");
        }
    }

    private function requireMutableOwnedPage(int $id, int $userId): void
    {
        $page = $this->pages->findOwnedById($id, $userId);

        if ($page === null) {
            Response::error("Page introuvable", 404);
        }

        if ($page["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas etre modifiee", 403);
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
