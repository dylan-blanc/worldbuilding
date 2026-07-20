<?php

declare(strict_types=1);

final class OwnedPageController
{
    private OwnedPage $ownedPages;

    public function __construct(PDO $pdo)
    {
        $this->ownedPages = new OwnedPage($pdo);
    }

    public function index(): void
    {
        Response::json(200, [
            "pages" => $this->ownedPages->findByOwnerId($this->authenticatedUserId()),
        ]);
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

        $updatedPage = $this->ownedPages->updateSettings($id, $userId, $status, $body["is_anonymous"]);

        if ($updatedPage === null) {
            Response::error("Page introuvable", 404);
        }

        if ($updatedPage["page_status"] === "banned") {
            Response::error("Une page bannie ne peut pas etre modifiee", 403);
        }

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
