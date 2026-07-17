<?php

declare(strict_types=1);

defined("DB_HOST") || define("DB_HOST", envValue("DB_HOST", "db"));
defined("DB_PORT") || define("DB_PORT", envValue("DB_PORT", "3306"));
defined("DB_NAME") || define("DB_NAME", envValue("DB_NAME", "dbname"));
defined("DB_USER") || define("DB_USER", envValue("DB_USER", "dbuser"));
defined("DB_PASSWORD") || define("DB_PASSWORD", envValue("DB_PASSWORD", "dbpassword"));

global $pdo;

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";

    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    http_response_code(500);

    if (!headers_sent()) {
        header("Content-Type: application/json; charset=utf-8");
    }

    echo json_encode([
        "error" => "Echec de la connexion a la base de donnees"
    ], JSON_UNESCAPED_UNICODE);

    exit(1);
}
