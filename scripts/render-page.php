<?php

declare(strict_types=1);

/**
 * Render one application route to stdout for the static GitHub Pages build.
 * Example: php scripts/render-page.php /staza/staza-br-5-jokina-cuprija-jelje-konjoder
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('HAJKIC_RENDER_ONLY', true);

$uri = $argv[1] ?? '/';
$parts = parse_url($uri);
$path = $parts['path'] ?? '/';
$query = $parts['query'] ?? '';

$_SERVER['REQUEST_URI'] = $path . ($query !== '' ? '?' . $query : '');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = (string) (parse_url((string) (getenv('HAJKIC_BASE_URL') ?: 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
$_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
$_SERVER['SERVER_PORT'] = str_starts_with((string) (getenv('HAJKIC_BASE_URL') ?: ''), 'https://') ? '443' : '80';
$_SERVER['HTTPS'] = str_starts_with((string) (getenv('HAJKIC_BASE_URL') ?: ''), 'https://') ? 'on' : 'off';

$_GET = [];
if ($query !== '') {
    parse_str($query, $_GET);
}

require dirname(__DIR__) . '/index.php';
