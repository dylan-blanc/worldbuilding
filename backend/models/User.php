<?php

declare(strict_types=1);

final class User
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, username, useremail, userpassword, profil_picture, created_at
            FROM users
            WHERE id = :id
            LIMIT 1"
        );
        $statement->bindValue(":id", $id, PDO::PARAM_INT);
        $statement->execute();

        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, username, useremail, userpassword, profil_picture, created_at
            FROM users
            WHERE useremail = :useremail
            LIMIT 1"
        );
        $statement->execute([
            "useremail" => $email,
        ]);

        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function existsByUsernameOrEmail(string $username, string $email): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id
            FROM users
            WHERE username = :username OR useremail = :useremail
            LIMIT 1"
        );
        $statement->execute([
            "username" => $username,
            "useremail" => $email,
        ]);

        return $statement->fetch() !== false;
    }

    public function create(string $username, string $email, string $passwordHash): array
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO users (username, useremail, userpassword)
            VALUES (:username, :useremail, :userpassword)"
        );
        $statement->execute([
            "username" => $username,
            "useremail" => $email,
            "userpassword" => $passwordHash,
        ]);

        $user = $this->findById((int) $this->pdo->lastInsertId());

        if ($user === null) {
            throw new RuntimeException("Utilisateur introuvable apres creation");
        }

        return $user;
    }
}
