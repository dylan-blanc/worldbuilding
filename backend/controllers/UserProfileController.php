<?php

declare(strict_types=1);

/**
 * Serves the authenticated profile API used by frontend/app/views/userprofile.vue.
 * GET /api/me reads User and UserProfil SQL data, then lists MinIO profile history.
 * POST /api/me verifies the current password, validates fields/file, uploads to MinIO,
 * then calls User::updateProfile() and returns the refreshed profile.
 * GET /api/me/picture validates ownership before streaming a private MinIO image.
 */
final class UserProfileController
{
    private const MAX_FIELD_LENGTH = 255;
    private const MIN_PASSWORD_LENGTH = 8;
    private const OUTPUT_MIMES = ["image/jpeg", "image/png", "image/webp", "image/avif", "image/gif"];

    private User $users;
    private UserProfil $profiles;
    private MinioStorage $storage;

    public function __construct(PDO $pdo)
    {
        $this->users = new User($pdo);
        $this->profiles = new UserProfil($pdo);

        try {
            $this->storage = new MinioStorage();
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            Response::error("Stockage des images de profil indisponible", 503, "profile_storage_unavailable");
        }
    }

    public function show(): void
    {
        $userId = $this->authenticatedUserId();
        $user = $this->authenticatedUser($userId);

        Response::json(200, $this->profilePayload($user));
    }

    public function update(): void
    {
        Request::requireSameOrigin();
        $userId = $this->authenticatedUserId();
        $user = $this->authenticatedUser($userId);
        $currentPassword = Request::field($_POST, ["current_password"], false);
        $username = Request::field($_POST, ["username"]);
        $email = strtolower(Request::field($_POST, ["useremail", "email"]));
        $newPassword = Request::field($_POST, ["new_password"], false);
        $selectedPicture = Request::field($_POST, ["selected_picture"]);
        $requiresPassword = (
            $username !== (string) $user["username"]
            || $email !== strtolower((string) $user["useremail"])
            || $newPassword !== ""
        );

        if ($requiresPassword && ($currentPassword === "" || !password_verify($currentPassword, (string) $user["userpassword"]))) {
            Response::error("Mot de passe actuel incorrect", 401, "invalid_current_password");
        }

        $this->validateIdentity($username, $email);
        $newPassword !== "" && $this->validatePassword($newPassword);

        if ($this->users->existsByUsernameOrEmailExceptId($username, $email, $userId)) {
            Response::error("Nom utilisateur ou email deja utilise", 409, "profile_identity_conflict");
        }

        $profilePicture = $this->selectedPicture($selectedPicture, $user, $userId);

        if (isset($_FILES["profile_picture"]) && ($_FILES["profile_picture"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $profilePicture = $this->uploadPicture($userId);
        }

        try {
            $updatedUser = $this->users->updateProfile(
                $userId,
                $username,
                $email,
                $newPassword === "" ? null : password_hash($newPassword, PASSWORD_DEFAULT),
                $profilePicture
            );
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                Response::error("Nom utilisateur ou email deja utilise", 409, "profile_identity_conflict");
            }

            throw $exception;
        }

        Response::json(200, [
            "message" => "Profil mis a jour",
            ...$this->profilePayload($updatedUser),
        ]);
    }

    public function picture(): void
    {
        $userId = $this->authenticatedUserId();
        $key = isset($_GET["key"]) && is_scalar($_GET["key"]) ? (string) $_GET["key"] : "";

        if (!MediaUploadValidator::isOwnedProfilePictureKey($key, $userId)) {
            Response::error("Image de profil introuvable", 404, "profile_picture_not_found");
        }

        try {
            $result = $this->storage->read($key);
        } catch (Throwable $exception) {
            error_log("Profile picture read failed: " . $exception->getMessage());
            Response::error("Image de profil introuvable", 404, "profile_picture_not_found");
        }

        $mime = (string) ($result["ContentType"] ?? "");

        if (!in_array($mime, self::OUTPUT_MIMES, true)) {
            Response::error("Format d'image de profil invalide", 415, "invalid_profile_picture");
        }

        http_response_code(200);
        header("Content-Type: " . $mime);
        header("Content-Disposition: inline");
        header("X-Content-Type-Options: nosniff");
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header("Cache-Control: private, no-store");
        isset($result["ContentLength"]) && header("Content-Length: " . (int) $result["ContentLength"]);
        $body = $result["Body"];

        while (!$body->eof()) {
            echo $body->read(1024 * 1024);
            flush();
        }

        exit;
    }

    private function validateIdentity(string $username, string $email): void
    {
        if ($username === "" || $email === "") {
            Response::error("Nom utilisateur et email requis", 422, "profile_identity_required");
        }

        if (strlen($username) > self::MAX_FIELD_LENGTH || strlen($email) > self::MAX_FIELD_LENGTH) {
            Response::error("Nom utilisateur ou email trop long", 422, "profile_identity_too_long");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error("Email invalide", 422, "invalid_profile_email");
        }
    }

    private function validatePassword(string $password): void
    {
        if (
            strlen($password) < self::MIN_PASSWORD_LENGTH
            || preg_match("/[A-Z]/", $password) !== 1
            || preg_match("/[0-9]/", $password) !== 1
            || preg_match("/[^A-Za-z0-9\s]/", $password) !== 1
        ) {
            Response::error(
                "Le mot de passe doit contenir au moins 8 caracteres, une majuscule, un chiffre et un caractere special",
                422,
                "invalid_profile_password"
            );
        }
    }

    private function selectedPicture(string $selectedPicture, array $user, int $userId): ?string
    {
        $currentPicture = isset($user["profil_picture"]) ? (string) $user["profil_picture"] : null;

        if ($selectedPicture === "" || $selectedPicture === $currentPicture) {
            return $currentPicture;
        }

        $availableKeys = array_column($this->profilePictures($userId), "key");

        if (
            !MediaUploadValidator::isOwnedProfilePictureKey($selectedPicture, $userId)
            || !in_array($selectedPicture, $availableKeys, true)
        ) {
            Response::error("Image de profil selectionnee invalide", 422, "invalid_selected_profile_picture");
        }

        return $selectedPicture;
    }

    private function uploadPicture(int $userId): string
    {
        try {
            $media = MediaUploadValidator::validate($_FILES["profile_picture"], "image");
            $objectKey = "User/" . $userId . "/profilepicture/" . bin2hex(random_bytes(16)) . "." . $media["extension"];
            $this->storage->upload($objectKey, $media);
        } catch (DomainException $exception) {
            Response::error($exception->getMessage(), 422, "invalid_profile_picture");
        } catch (Throwable $exception) {
            error_log("Profile picture upload failed: " . $exception->getMessage());
            Response::error("Enregistrement de l'image de profil impossible", 503, "profile_picture_upload_failed");
        } finally {
            isset($media) && ($media["cleanup"] ?? false) && @unlink((string) $media["path"]);
        }

        return $objectKey;
    }

    private function profilePayload(array $user): array
    {
        return [
            "user" => [
                "id" => (int) $user["id"],
                "username" => (string) $user["username"],
                "useremail" => (string) $user["useremail"],
                "profil_picture" => $user["profil_picture"],
                "created_at" => (string) $user["created_at"],
            ],
            "stats" => $this->profiles->findContentTotals((int) $user["id"]),
            "profile_pictures" => $this->profilePictures((int) $user["id"]),
        ];
    }

    private function profilePictures(int $userId): array
    {
        try {
            return $this->storage->list("User/" . $userId . "/profilepicture/");
        } catch (Throwable $exception) {
            error_log("Profile picture listing failed: " . $exception->getMessage());

            // Profile identity and statistics remain available if MinIO history cannot be listed.
            return [];
        }
    }

    private function authenticatedUserId(): int
    {
        $userId = Session::userId();

        if ($userId === null) {
            Response::error("Non authentifie", 401, "authentication_required");
        }

        return $userId;
    }

    private function authenticatedUser(int $userId): array
    {
        $user = $this->users->findById($userId);

        if ($user === null) {
            Session::logout();
            Response::error("Non authentifie", 401, "authentication_required");
        }

        return $user;
    }
}
