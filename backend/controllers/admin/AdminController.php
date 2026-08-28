<?php

declare(strict_types=1);

/**
 * Serves filter administration endpoints used by frontend/app/views/adminfilter.vue.
 * Every action follows session -> User::isAdmin() SQL authorization before Filter SQL reads/writes.
 * GET /api/admin/filters lists the hierarchy, POST creates through Filter::create(),
 * PATCH /api/admin/filters/{id} moves a leaf and DELETE removes an unused leaf through Filter.
 */
final class AdminController
{
    private const FILTER_TYPES = ["theme", "category", "subcategory", "moderation"];
    private const MAX_FILTER_NAME_LENGTH = 255;

    private Filter $filters;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->filters = new Filter($pdo);
        $this->users = new User($pdo);
    }

    public function filters(): void
    {
        $this->requireAdmin();

        Response::json(200, [
            "filters" => $this->filters->findAll(),
        ]);
    }

    public function createFilter(): void
    {
        Request::requireSameOrigin();
        $this->requireAdmin();
        $body = Request::body();
        $name = Request::field($body, ["filter_name", "name"]);
        $type = Request::field($body, ["filter_type", "type"]);
        $withoutParent = $this->booleanField($body, "without_parent");
        $belongTo = $this->optionalPositiveIntField($body, "belong_to");

        $this->validateName($name);
        $this->validateType($type);
        $belongTo = $this->validatedParent($type, $withoutParent ? null : $belongTo, !$withoutParent);

        try {
            $filter = $this->filters->create($name, $type, $belongTo);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                Response::error("Ce filtre existe deja", 409, "filter_name_conflict");
            }

            throw $exception;
        }

        Response::json(201, [
            "message" => "Filtre cree",
            "filter" => $filter,
        ]);
    }

    public function moveFilter(int $id): void
    {
        Request::requireSameOrigin();
        $this->requireAdmin();
        $body = Request::body();
        $type = Request::field($body, ["filter_type", "type"]);
        $belongTo = $this->optionalPositiveIntField($body, "belong_to");
        $filter = $this->filters->findById($id);

        if ($filter === null) {
            Response::error("Filtre introuvable", 404, "filter_not_found");
        }

        $this->validateType($type);
        $belongTo = $this->validatedParent($type, $belongTo, false, $id);
        $hasChanged = (
            $type !== (string) $filter["filter_type"]
            || $belongTo !== ($filter["belong_to"] === null ? null : (int) $filter["belong_to"])
        );

        if ($hasChanged && $this->filters->findChildrenById($id) !== []) {
            Response::error(
                "Un filtre avec des enfants ne peut pas etre deplace",
                409,
                "filter_has_children"
            );
        }

        $updatedFilter = $hasChanged
            ? $this->filters->update($id, (string) $filter["filter_name"], $type, $belongTo)
            : $filter;

        if ($updatedFilter === null) {
            Response::error("Filtre introuvable", 404, "filter_not_found");
        }

        Response::json(200, [
            "message" => "Filtre deplace",
            "filter" => $updatedFilter,
        ]);
    }

    public function deleteFilter(int $id): void
    {
        Request::requireSameOrigin();
        $this->requireAdmin();
        $filter = $this->filters->findById($id);

        if ($filter === null) {
            Response::error("Filtre introuvable", 404, "filter_not_found");
        }

        if ($this->filters->findChildrenById($id) !== []) {
            Response::error(
                "Un filtre parent ayant des enfants ne peut pas etre supprime",
                409,
                "filter_has_children"
            );
        }

        if ((string) $filter["filter_type"] === "moderation"
            && $this->filters->hasPendingModerationUsage($id)) {
            Response::error(
                "Un contenu utilisant ce filtre est en cours de modération, veuillez le modérer ou le clore avant de supprimer ce filtre",
                409,
                "filter_used_by_pending_moderation"
            );
        }

        if (!$this->filters->delete($id)) {
            Response::error("Filtre introuvable", 404, "filter_not_found");
        }

        Response::json(200, [
            "message" => "Filtre supprime",
        ]);
    }

    private function requireAdmin(): int
    {
        $userId = Session::userId();

        if ($userId === null || !$this->users->isAdmin($userId)) {
            Response::error("Acces refuse", 403, "access_denied");
        }

        Session::renewForMutation();

        return $userId;
    }

    private function validateName(string $name): void
    {
        if ($name === "") {
            Response::error("Nom du filtre requis", 422, "filter_name_required");
        }

        if (strlen($name) > self::MAX_FILTER_NAME_LENGTH) {
            Response::error("Nom du filtre trop long", 422, "filter_name_too_long");
        }
    }

    private function validateType(string $type): void
    {
        if (!in_array($type, self::FILTER_TYPES, true)) {
            Response::error("Type de filtre invalide", 422, "invalid_filter_type");
        }
    }

    private function validatedParent(
        string $type,
        ?int $belongTo,
        bool $parentRequired,
        ?int $filterId = null
    ): ?int {
        if ($type === "theme") {
            return null;
        }

        if ($belongTo === null) {
            $parentRequired && Response::error("Filtre parent requis", 422, "filter_parent_required");

            return null;
        }

        if ($filterId !== null && $belongTo === $filterId) {
            Response::error("Un filtre ne peut pas etre son propre parent", 422, "invalid_filter_parent");
        }

        $parent = $this->filters->findById($belongTo);

        if ($type === "moderation") {
            if ($parent === null
                || (string) $parent["filter_type"] !== "moderation"
                || $parent["belong_to"] !== null) {
                Response::error(
                    "Un sous-motif doit appartenir a un motif de moderation racine",
                    422,
                    "invalid_moderation_filter_parent"
                );
            }

            return $belongTo;
        }

        $expectedParentType = $type === "category" ? "theme" : "category";

        if ($parent === null || (string) $parent["filter_type"] !== $expectedParentType) {
            Response::error("Type du filtre parent invalide", 422, "invalid_filter_parent");
        }

        return $belongTo;
    }

    private function optionalPositiveIntField(array $body, string $key): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === "") {
            return null;
        }

        if (!is_scalar($body[$key])) {
            Response::error("Identifiant du filtre parent invalide", 422, "invalid_filter_parent");
        }

        $value = filter_var($body[$key], FILTER_VALIDATE_INT, [
            "options" => ["min_range" => 1],
        ]);

        if ($value === false) {
            Response::error("Identifiant du filtre parent invalide", 422, "invalid_filter_parent");
        }

        return (int) $value;
    }

    private function booleanField(array $body, string $key): bool
    {
        if (!array_key_exists($key, $body)) {
            return false;
        }

        $value = filter_var($body[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            Response::error("Option sans parent invalide", 422, "invalid_without_parent");
        }

        return $value;
    }
}
