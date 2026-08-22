<?php

declare(strict_types=1);

/**
 * Handles the authentication endpoints registered by backend/routes/shared/auth.php.
 * Registration follows POST /api/register -> register() -> validateRegister()
 * -> User::existsByUsernameOrEmail()/create() -> SQL users table -> WelcomeEmailService
 * -> static backend HTML -> BrevoMailer -> POST /v3/smtp/email -> session JSON response.
 * A template or Brevo failure is logged generically and never rolls back the created user.
 * Login follows POST /api/login -> login() -> User::findByEmail() -> password verification
 * -> session JSON response. GET /api/admin/access follows Nginx auth_request -> session
 * -> User::isAdmin() SQL check -> uniform denial or empty authorization response.
 */
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

        $this->sendWelcomeEmail($email);

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

    public function logout(): void
    {
        Session::logout();

        Response::json(200, [
            "message" => "Deconnexion reussie",
        ]);
    }

    public function adminAccess(): void
    {
        $userId = Session::userId();

        if ($userId === null || !$this->users->isAdmin($userId)) {
            Response::error("Acces refuse", 403, "access_denied");
        }

        Response::noContent();
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

        if (
            strlen($password) < self::MIN_PASSWORD_LENGTH
            || preg_match("/[A-Z]/", $password) !== 1
            || preg_match("/[0-9]/", $password) !== 1
            || preg_match("/[^A-Za-z0-9\s]/", $password) !== 1
        ) {
            Response::error("Le mot de passe doit contenir au moins 8 caracteres, une majuscule, un chiffre et un caractere special", 422);
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

    private function sendWelcomeEmail(string $email): void
    {
        try {
            WelcomeEmailService::fromEnvironment()->send($email);
        } catch (Throwable) {
            // Registration remains successful when the external email path is unavailable.
            error_log("Welcome email dispatch failed");
        }
    }

    private function publicUser(array $user): array
    {
        return [
            "id" => (int) $user["id"],
            "username" => (string) $user["username"],
            "useremail" => (string) $user["useremail"],
            "profil_picture" => $user["profil_picture"],
            "roles" => (string) $user["roles"],
            "created_at" => (string) $user["created_at"],
        ];
    }
}
