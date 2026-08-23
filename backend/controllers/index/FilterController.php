<?php

declare(strict_types=1);

/**
 * Lists public page filters for GET /filters.
 * Requests optionally select one type, then Filter reads the theme/category/subcategory hierarchy from SQL.
 */
final class FilterController
{
    private Filter $filters;

    public function __construct(PDO $pdo)
    {
        $this->filters = new Filter($pdo);
    }

    public function index(): void
    {
        $type = (string) ($_GET["type"] ?? "");

        if ($type === "") {
            Response::json(200, [
                "filters" => $this->filters->findNavigational(),
            ]);
        }

        try {
            $filters = $this->filters->findByType($type);
        } catch (InvalidArgumentException $exception) {
            Response::error($exception->getMessage(), 422);
        }

        Response::json(200, [
            "filters" => $filters,
        ]);
    }
}
