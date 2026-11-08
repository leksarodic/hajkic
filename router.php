<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server (local development only):
 *   php -S 127.0.0.1:8080 router.php
 *
 * On shared hosting Apache uses .htaccess instead.
 */

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$decoded = rawurldecode($path);

$blocked = ['/config/', '/includes/', '/scripts/', '/storage/', '/tests/', '/docs/', '/.git/'];
foreach ($blocked as $prefix) {
    if (str_starts_with($decoded, $prefix)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        return true;
    }
}

if ($decoded !== '/' && is_file(__DIR__ . $decoded)) {
    return false;
}

require __DIR__ . '/index.php';
return true;
