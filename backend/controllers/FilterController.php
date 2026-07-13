<?php

declare(strict_types=1);

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
            Response::error("Type de filtre requis", 422);
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
