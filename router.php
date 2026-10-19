<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$decodedPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
$requestedFile = realpath(
    __DIR__ . DIRECTORY_SEPARATOR
    . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $decodedPath), DIRECTORY_SEPARATOR)
);
$databaseFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'database.sqlite');
$isDatabaseFile = preg_match('/\.(?:sqlite|sqlite3|db)(?:[-.][A-Za-z0-9._-]+)?\z/i', basename($decodedPath)) === 1;

if (
    $isDatabaseFile ||
    ($requestedFile !== false && $databaseFile !== false && $requestedFile === $databaseFile)
) {
    http_response_code(404);
    exit('Not found.');
}

return false;
