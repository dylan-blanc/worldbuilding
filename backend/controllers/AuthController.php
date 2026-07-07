<?php

declare(strict_types=1);

require_once __DIR__ . "/../core/Request.php";
require_once __DIR__ . "/../core/Response.php";
require_once __DIR__ . "/../core/Session.php";
require_once __DIR__ . "/../models/User.php";

final class AuthController
{
    private const USERNAME_KEYS = ["username"];
    private const EMAIL_KEYS = ["useremail", "mail", "email"];
    private const PASSWORD_KEYS = ["userpassword", "password"];
    private const MAX_FIELD_LENGTH = 255;
    private const MIN_PASSWORD_LENGTH = 8;

    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->users = new User($pdo);
    }

    public function register(): void
    {
        $body = Request::body();
        $username = Request::field($body, self::USERNAME_KEYS);
        $email = strtolower(Request::field($body, self::EMAIL_KEYS));
        $password = Request::field($body, self::PASSWORD_KEYS, false);

        $this->validateRegister($username, $email, $password);

        if ($this->users->existsByUsernameOrEmail($username, $email)) {
            Response::error("Nom utilisateur ou email deja utilise", 409);
        }

        try {
            $user = $this->users->create($username, $email, password_hash($password, PASSWORD_DEFAULT));
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                Response::error("Nom utilisateur ou email deja utilise", 409);
            }

            throw $exception;
        }

        $this->respondWithSession("Inscription reussie", $user, 201);
    }

    public function login(): void
    {
        $body = Request::body();
        $email = strtolower(Request::field($body, self::EMAIL_KEYS));
        $password = Request::field($body, self::PASSWORD_KEYS, false);

        if ($email === "" || $password === "") {
            Response::error("Email et mot de passe requis", 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error("Email invalide", 422);
        }

        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, (string) $user["userpassword"])) {
            Response::error("Identifiants invalides", 401);
        }

        $this->respondWithSession("Connexion reussie", $user, 200);
    }

    public function me(): void
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Non authentifie", 401);
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            Session::logout();
            Response::error("Non authentifie", 401);
        }

        Response::json(200, [
            "user" => $this->publicUser($user),
        ]);
    }

    public function logout(): void
    {
        Session::logout();

        Response::json(200, [
            "message" => "Deconnexion reussie",
        ]);
    }

    private function validateRegister(string $username, string $email, string $password): void
    {
        if ($username === "" || $email === "" || $password === "") {
            Response::error("Nom utilisateur, email et mot de passe requis", 422);
        }

        if (strlen($username) > self::MAX_FIELD_LENGTH || strlen($email) > self::MAX_FIELD_LENGTH) {
            Response::error("Nom utilisateur ou email trop long", 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error("Email invalide", 422);
        }

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            Response::error("Le mot de passe doit contenir au moins 8 caracteres", 422);
        }
    }

    private function respondWithSession(string $message, array $user, int $status): void
    {
        Session::login($user);

        Response::json($status, [
            "message" => $message,
            "user" => $this->publicUser($user),
        ]);
    }

    private function publicUser(array $user): array
    {
        return [
            "id" => (int) $user["id"],
            "username" => (string) $user["username"],
            "useremail" => (string) $user["useremail"],
            "profil_picture" => $user["profil_picture"],
            "created_at" => (string) $user["created_at"],
        ];
    }
}
