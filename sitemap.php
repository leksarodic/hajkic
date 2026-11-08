<?php

declare(strict_types=1);

/**
 * Dynamic sitemap.xml — static pages plus every locally known trail.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$repo = new Hajkic\Data\TrailRepository();
$index = hajkic_read_json($repo->dataDir() . '/trails-index.json');
$trails = (array) ($index['trails'] ?? []);

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$static = [
    '/'           => '1.0',
    '/staze'      => '0.9',
    '/o-projektu' => '0.6',
];

foreach ($static as $path => $priority) {
    echo '  <url><loc>' . htmlspecialchars(hajkic_absolute_url($path), ENT_XML1) . '</loc>';
    echo '<changefreq>weekly</changefreq><priority>' . $priority . '</priority></url>' . "\n";
}

foreach ($trails as $trail) {
    $slug = (string) ($trail['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    $updated = $trail['source']['updated_at'] ?? null;
    echo '  <url><loc>' . htmlspecialchars(hajkic_absolute_url('/staza/' . $slug), ENT_XML1) . '</loc>';
    if ($updated !== null) {
        try {
            echo '<lastmod>' . (new DateTimeImmutable((string) $updated))->format('Y-m-d') . '</lastmod>';
        } catch (Exception) {
            // Ignore malformed source date.
        }
    }
    echo '<changefreq>monthly</changefreq><priority>0.7</priority></url>' . "\n";
}

echo '</urlset>' . "\n";
