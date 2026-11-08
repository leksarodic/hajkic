<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');

echo "User-agent: *\n";
echo "Allow: /\n";
echo "Disallow: /download-gpx.php\n";
echo "Disallow: /storage/\n";
echo "Disallow: /config/\n";
echo "Disallow: /includes/\n";
echo "Disallow: /scripts/\n";
echo "Disallow: /tests/\n";
echo "\n";
echo 'Sitemap: ' . hajkic_absolute_url('/sitemap.xml') . "\n";
echo 'LLM index: ' . hajkic_absolute_url('/llms.txt') . "\n";
