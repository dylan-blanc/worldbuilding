<?php

declare(strict_types=1);

/**
 * Serves the authenticated page-management screen through /me/owned-pages routes.
 * Reads combine OwnedPage and PageFilter SQL results; settings updates validate metadata, then atomically
 * write title, filters, visibility and anonymity through Page, PageFilter and OwnedPage.
 */
final class OwnedPageController
{
    private PDO $pdo;
    private OwnedPage $ownedPages;
    private Page $pages;
    private PageFilter $pageFilters;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ownedPages = new OwnedPage($pdo);
        $this->pages = new Page($pdo);
        $this->pageFilters = new PageFilter($pdo);
    }

    public function index(): void
    {
        $pages = $this->ownedPages->findByOwnerId($this->authenticatedUserId());

        foreach ($pages as &$page) {
            $page["filters"] = $this->pageFilters->findByPageId((int) $page["id"]);
        }

        unset($page);

        Response::json(200, ["pages" => $pages]);
    }

    public function updateSettings(int $id): void
    {
        $userId = $this->authenticatedUserId();
        $page = $this->ownedPages->findOwnedById($id, $userId);

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
            $updatedPage = $this->ownedPages->updateSettings($id, $userId, $status, $body["is_anonymous"]);
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

        if ($updatedPage["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas etre modifiee", 403);
        }

        $updatedPage["filters"] = $filters;

        Response::json(200, [
            "message" => "Parametres de page mis a jour",
            "page" => $updatedPage,
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
}
