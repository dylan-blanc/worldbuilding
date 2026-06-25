<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    echo json_encode([
        'status' => 'ok',
        'service' => 'worldbuilding-api',
    ]);
    exit;
}

http_response_code(404);

echo json_encode([
    'error' => 'Not found',
]);
