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
        $userId = $this->authenticatedUserId();

        try {
            $count = $this->engagements->addFavorite($pageId, $userId);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 404);
        }

        Response::json(201, [
            "is_favorite" => true,
            "number_of_favorites" => $count,
        ]);
    }

    public function removeFavorite(int $pageId): void
    {
        $userId = $this->authenticatedUserId();

        try {
            $count = $this->engagements->removeFavorite($pageId, $userId);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 404);
        }

        Response::json(200, [
            "is_favorite" => false,
            "number_of_favorites" => $count,
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
}
