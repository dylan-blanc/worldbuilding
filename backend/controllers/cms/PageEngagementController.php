<?php

declare(strict_types=1);

/**
 * Handles authenticated favorite mutations dispatched by routes/cms/pages.php.
 * POST/DELETE /pages/{id}/favorite resolves Session user_id, calls PageEngagement,
 * then returns the current favorite state and pages.number_of_favorites counter.
 */
final class PageEngagementController
{
    private PageEngagement $engagements;

    public function __construct(PDO $pdo)
    {
        $this->engagements = new PageEngagement($pdo);
    }

    public function addFavorite(int $pageId): void
    {
        $this->respond("addFavorite", $pageId, 201);
    }

    public function removeFavorite(int $pageId): void
    {
        $this->respond("removeFavorite", $pageId, 200);
    }

    public function addLike(int $pageId): void
    {
        $this->respond("addLike", $pageId, 201);
    }

    public function removeLike(int $pageId): void
    {
        $this->respond("removeLike", $pageId, 200);
    }

    public function addFollow(int $pageId): void
    {
        $this->respond("addFollow", $pageId, 201);
    }

    public function removeFollow(int $pageId): void
    {
        $this->respond("removeFollow", $pageId, 200);
    }

    private function respond(string $method, int $pageId, int $status): void
    {
        $userId = $this->authenticatedUserId();

        try {
            $engagement = $this->engagements->{$method}($pageId, $userId);
        } catch (DomainException $exception) {
            $likeRequired = $exception->getMessage() === "Retirez cette page des favoris avant de retirer son like";
            Response::error(
                $exception->getMessage(),
                $likeRequired ? 409 : 404,
                $likeRequired ? "favorite_requires_like" : "page_not_found"
            );
        }

        Response::json($status, $engagement);
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
}
