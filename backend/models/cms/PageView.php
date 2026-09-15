<?php

declare(strict_types=1);

/**
 * Records each public page consultation initiated by PageController::show() for GET /pages/{id}.
 * The write flow is controller -> PageView::record() -> page_view_events INSERT and pages counter UPDATE;
 * Page::findPublicCards() later aggregates these timestamped events for rolling popularity periods.
 */
final class PageView
{
    public function __construct(private PDO $pdo) {}

    public function record(int $pageId): void
    {
        $this->pdo->beginTransaction();

        try {
            $page = $this->pdo->prepare("UPDATE pages
                SET number_of_view = number_of_view + 1,
                    updated_at = updated_at
                WHERE id = :page_id AND page_status = :page_status");
            $page->execute([
                ":page_id" => $pageId,
                ":page_status" => "public",
            ]);

            if ($page->rowCount() !== 1) {
                throw new RuntimeException("Page publique introuvable");
            }

            $view = $this->pdo->prepare("INSERT INTO page_view_events (page_id)
                VALUES (:page_id)");
            $view->execute([
                ":page_id" => $pageId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->inTransaction() && $this->pdo->rollBack();
            throw $exception;
        }
    }
}
