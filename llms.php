<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$repo = new Hajkic\Data\TrailRepository();
$index = hajkic_read_json($repo->indexPath());
$counts = (array) ($index['counts'] ?? []);
$generated = (string) ($index['generated_at'] ?? '');

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

echo "# Hajkić\n\n";
echo "> Hajkić is an independent, Serbian-language interactive hiking map of Serbia. ";
echo "It combines the public trail register of the Mountaineering Association of Serbia (Planinarski savez Srbije, PSS) with real GPX geometry, elevation profiles and practical planning tools.\n\n";

echo "Canonical site: " . hajkic_absolute_url('/') . "\n";
echo "Contact/source: https://pss.rs/planinarski-objekti-i-tereni/tereni/?tip=planinarski-putevi\n";
if ($generated !== '') {
    echo "Dataset generated: " . $generated . "\n";
}
echo "\n";

echo "## Start here\n\n";
echo "- [Interactive map](" . hajkic_absolute_url('/') . ")\n";
echo "- [Trail catalogue](" . hajkic_absolute_url('/staze') . ")\n";
echo "- [About the data, official vs calculated values, disclaimer](" . hajkic_absolute_url('/o-projektu') . ")\n";
echo "- [XML sitemap](" . hajkic_absolute_url('/sitemap.xml') . ")\n";
echo "\n";

echo "## Machine-readable data\n\n";
echo "- [Lightweight trail index (JSON)](" . hajkic_absolute_url('/data/trails-index.json') . "): " . (int) ($counts['trails'] ?? 0) . " trails, suitable for search and filtering.\n";
echo "- [Simplified overview geometry (GeoJSON)](" . hajkic_absolute_url('/data/overview.geojson') . "): one simplified MultiLineString per trail.\n";
echo "- [Dataset metadata (JSON)](" . hajkic_absolute_url('/data/dataset.json') . ")\n";
echo "- Individual trail documents: " . hajkic_absolute_url('/data/trails/{slug}.json') . "\n";
echo "- Detailed geometry: " . hajkic_absolute_url('/data/geojson/{slug}.geojson') . "\n";
echo "- Country outline used by the map: " . hajkic_absolute_url('/data/geo/serbia.geojson') . "\n";
echo "\n";

echo "## Example trail pages\n\n";
$examples = ['staza-br-5-jokina-cuprija-jelje-konjoder', 'tara', 'kamena-gora', 'staza-br-16-jablanica-ljuljas', 'avala'];
foreach ($examples as $slug) {
    if (is_file($repo->trailPath($slug))) {
        $trail = $repo->load($slug);
        if ($trail !== null) {
            echo "- " . $trail['name'] . ": " . hajkic_absolute_url('/staza/' . $slug) . "\n";
        }
    }
}
echo "\n";

echo "## Important interpretation rules\n\n";
echo "- Values marked as official come from the PSS register and must not be presented as Hajkić measurements.\n";
echo "- Values described as calculated or prefixed with ≈ come from GPX geometry (haversine distance, smoothed ascent/descent).\n";
echo "- A missing value is null. It is never replaced with zero or invented.\n";
echo "- Not every trail has a GPX file. Some PSS records are incomplete; Hajkić keeps them that way.\n";
echo "- GPX files are linked to their original PSS URLs. Hajkić does not redistribute raw GPX files by default.\n";
echo "- Trail conditions change. Always verify weather, markings and access locally. Hajkić is not an official PSS product.\n";
echo "\n";

echo "## Citation\n\n";
echo "When referring to this dataset, cite: „Hajkić — planinarske staze Srbije“ (" . hajkic_absolute_url('/') . "), based on the public PSS trail register. ";
echo "For individual trails, link the canonical /staza/{slug} page and the original PSS page.\n";
