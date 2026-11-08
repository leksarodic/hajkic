<?php

declare(strict_types=1);

/**
 * Serve or redirect a GPX file.
 *
 * Default policy: link to the original PSS file. Local redistribution is opt-in
 * via config/import.redistribute_gpx and must be cleared against the source's
 * usage terms first.
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/includes/bootstrap.php';
} else {
    require_once __DIR__ . '/includes/bootstrap.php';
}

$slug = hajkic_slugify((string) ($_GET['slug'] ?? ''), 90);
if ($slug === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nedostaje slug.';
    exit;
}

$repo = new Hajkic\Data\TrailRepository();
$trail = $repo->load($slug);

if ($trail === null || !($trail['gpx']['available'] ?? false) || empty($trail['gpx']['source_url'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'GPX nije dostupan za ovu stazu.';
    exit;
}

$gpx = (array) $trail['gpx'];
$sourceUrl = (string) $gpx['source_url'];

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, max-age=300');

if (!(bool) config('import.redistribute_gpx', false)) {
    hajkic_redirect($sourceUrl, 302);
}

$localPath = HAJKIC_ROOT . '/storage/gpx/' . $slug . '.gpx';
if (!is_file($localPath)) {
    hajkic_redirect($sourceUrl, 302);
}

$filename = $slug . '.gpx';
header('Content-Type: application/gpx+xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($localPath));
readfile($localPath);
