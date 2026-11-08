<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$slug = isset($_GET['trail']) ? hajkic_slugify((string) $_GET['trail'], 90) : '';
$repo = new Hajkic\Data\TrailRepository();
$trail = $slug !== '' ? $repo->load($slug) : null;
$key = $trail !== null ? (string) $trail['slug'] : 'default';
$cacheDir = HAJKIC_ROOT . '/storage/cache/og';
hajkic_ensure_dir($cacheDir);
$version = (int) @filemtime(HAJKIC_ROOT . '/includes/Seo/OgImage.php');
$cachePath = $cacheDir . '/' . $key . '-' . $version . '.png';

$sourceMtime = $trail !== null ? (int) @filemtime($repo->trailPath((string) $trail['slug'])) : 0;
$cacheMtime = (int) @filemtime($cachePath);

if (!is_file($cachePath) || $cacheMtime < $sourceMtime || $cacheMtime < time() - 86400 * 7) {
    try {
        $png = (new Hajkic\Seo\OgImage())->render($trail);
        hajkic_write_atomic($cachePath, $png);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'OG slika nije dostupna.';
        exit;
    }
}

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . (string) filesize($cachePath));
readfile($cachePath);
