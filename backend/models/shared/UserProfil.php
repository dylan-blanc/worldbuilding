<?php

declare(strict_types=1);

/**
 * Aggregates content statistics displayed by frontend/app/views/userprofile.vue.
 * GET /api/me follows UserProfileController::show() -> findContentTotals()
 * -> SUM over pages owned by the session user -> JSON profile statistics.
 */
final class UserProfil
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findContentTotals(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(number_of_likes), 0) AS total_likes,
                COALESCE(SUM(number_of_followers), 0) AS total_followers
            FROM pages
            WHERE owner_user_id = :owner_user_id"
        );
        $statement->execute([
            ":owner_user_id" => $userId,
        ]);
        $totals = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            "total_likes" => (int) ($totals["total_likes"] ?? 0),
            "total_followers" => (int) ($totals["total_followers"] ?? 0),
        ];
    }
}
