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
    // used to simulate a password verification delay for non-existent users to mitigate timing attacks
    // usefull for login attempts with non-existent emails to avoid revealing valid emails through timing differences
    private const DUMMY_PASSWORD_HASH = "\$argon2id\$v=19\$m=19456,t=2,p=1\$ZkVLdDJLQ3ZxUGEwLm5vVg\$+n+Pd0tdKGMA/lId8b1TB6Y9EBhleo82i0+D+jthsks";

    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->users = new User($pdo);
    }

    //ANCHOR - Argon2id Controller (login and register)

    public function register(): void
    {
        // recupère les données du corps de la requête (POST) et les stocke dans des variables
        $body = Request::body();
        $username = Request::field($body, self::USERNAME_KEYS);
        // convertit l'email en minuscules pour éviter les problèmes de casse lors de la vérification
        $email = strtolower(Request::field($body, self::EMAIL_KEYS));
        $password = PasswordPolicy::normalize(Request::field($body, self::PASSWORD_KEYS, false));

        $this->validateRegister($username, $email, $password);
        // verifie si le nom d'utilisateur ou l'email existe déjà dans la base de données
        if ($this->users->existsByUsernameOrEmail($username, $email)) {
            Response::error("Nom utilisateur ou email deja utilise", 409);
        }

        try {
            $user = $this->users->create($username, $email, PasswordPolicy::hash($password));
        } catch (PDOException $exception) {
            // recupère l'erreur SQL et vérifie si c'est une erreur de duplication (code 1062)
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
        $rawPassword = Request::field($body, self::PASSWORD_KEYS, false);

        if ($email === "" || $rawPassword === "") {
            Response::error("Email et mot de passe requis", 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error("Email invalide", 422);
        }

        $user = $this->users->findByEmail($email);
        $normalizedPassword = PasswordPolicy::normalizeForVerification($rawPassword);

        $storedHash = (string) ($user["userpassword"] ?? self::DUMMY_PASSWORD_HASH);
        $passwordValid = password_verify($normalizedPassword, $storedHash);
        $legacyPasswordValid = !$passwordValid
            && $normalizedPassword !== $rawPassword
            && password_verify($rawPassword, $storedHash);

        if ($user === null || (!$passwordValid && !$legacyPasswordValid)) {
            Response::error("Identifiants invalides", 401);
        }

        if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID, PasswordPolicy::ARGON_OPTIONS)) {
            $this->users->updatePasswordHash((int) $user["id"], PasswordPolicy::hash($normalizedPassword));
        }

        $this->respondWithSession("Connexion reussie", $user, 200);
    }

    public function logout(): void
    {
        Session::logout();
        header("Cache-Control: no-store");

        Response::json(200, [
            "message" => "Deconnexion reussie",
        ]);
    }

    public function csrf(): void
    {
        header("Cache-Control: no-store");
        Response::json(200, [
            "csrf_token" => Session::csrfToken(),
        ]);
    }

    public function activity(): void
    {
        if (!Session::renewAuthenticated()) {
            Response::error("Non authentifie", 401, "authentication_required");
        }

        Response::noContent();
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
    }

    private function respondWithSession(string $message, array $user, int $status): void
    {
        Session::login($user);
        header("Cache-Control: no-store");

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
