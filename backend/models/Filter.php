<?php

declare(strict_types=1);

final class Filter
{
    private const TYPES = ["theme", "category", "subcategory"];

    public function __construct(private PDO $pdo) {}

    public function findAll(): array
    {
        $sql = "SELECT child.id, child.filter_name, child.filter_type, child.belong_to, parent.filter_name AS parent_name, child.created_at
            FROM filters child
            LEFT JOIN filters parent ON parent.id = child.belong_to
            ORDER BY child.filter_type, child.filter_name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT child.id, child.filter_name, child.filter_type, child.belong_to, parent.filter_name AS parent_name, child.created_at
            FROM filters child
            LEFT JOIN filters parent ON parent.id = child.belong_to
            WHERE child.id = :id
            LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
        ]);
        $stmt->execute();

        $filter = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($filter) ? $filter : null;
    }

    public function findByType(string $type): array
    {
        $this->validateType($type);

        $sql = "SELECT child.id, child.filter_name, child.filter_type, child.belong_to, parent.filter_name AS parent_name, child.created_at
            FROM filters child
            LEFT JOIN filters parent ON parent.id = child.belong_to
            WHERE child.filter_type = :filter_type
            ORDER BY child.filter_name";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":filter_type" => $type,
        ]);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findChildrenById(int $id): array
    {
        $sql = "SELECT id, filter_name, filter_type, belong_to, created_at
            FROM filters
            WHERE belong_to = :belong_to
            ORDER BY filter_type, filter_name";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":belong_to" => $id,
        ]);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findDescendantsById(int $id): array
    {
        $descendants = [];
        $pendingIds = [$id];

        while ($pendingIds !== []) {
            $children = $this->findChildrenById((int) array_shift($pendingIds));

            foreach ($children as $child) {
                $descendants[] = $child;
                $pendingIds[] = (int) $child["id"];
            }
        }

        return $descendants;
    }

    public function findExclusionIds(int $id): array
    {
        $filter = $this->findById($id);

        if ($filter === null) {
            return [];
        }

        $ids = [(int) $filter["id"]];

        foreach ($this->findDescendantsById($id) as $child) {
            $ids[] = (int) $child["id"];
        }

        return $ids;
    }

    public function create(string $name, string $type, ?int $belongTo = null): array
    {
        $this->validateType($type);

        $sql = "INSERT INTO filters (filter_name, filter_type, belong_to)
            VALUES (:filter_name, :filter_type, :belong_to)";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":filter_name" => $name,
            ":filter_type" => $type,
            ":belong_to" => $belongTo,
        ]);
        $stmt->execute();

        $filter = $this->findById((int) $this->pdo->lastInsertId());

        if ($filter === null) {
            throw new RuntimeException("Filtre introuvable apres creation");
        }

        return $filter;
    }

    public function update(int $id, string $name, string $type, ?int $belongTo = null): ?array
    {
        $this->validateType($type);

        $sql = "UPDATE filters
            SET filter_name = :filter_name,
                filter_type = :filter_type,
                belong_to = :belong_to
            WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
            ":filter_name" => $name,
            ":filter_type" => $type,
            ":belong_to" => $belongTo,
        ]);
        $stmt->execute();

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM filters
            WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $this->bindValues($stmt, [
            ":id" => $id,
        ]);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    private function validateType(string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Type de filtre invalide");
        }
    }

    private function bindValues(PDOStatement $stmt, array $values): void
    {
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value, $this->pdoParamType($value));
        }
    }

    private function pdoParamType(mixed $value): int
    {
        if ($value === null) {
            return PDO::PARAM_NULL;
        }

        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        return PDO::PARAM_STR;
    }
}
