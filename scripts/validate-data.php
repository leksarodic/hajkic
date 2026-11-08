<?php

declare(strict_types=1);

/**
 * Validate the local Hajkić dataset.
 *
 * Run: php scripts/validate-data.php [--strict] [--json=path]
 * Exit: 0 = ok (warnings allowed), 1 = errors, 2 = missing dataset.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$strict = in_array('--strict', $argv ?? [], true);
$jsonPath = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--json=')) {
        $jsonPath = substr($arg, 7);
    }
}

$repo = new Hajkic\Data\TrailRepository();
$errors = [];
$warnings = [];
$stats = [
    'trails' => 0,
    'geojson' => 0,
    'with_elevation' => 0,
    'checked' => 0,
];

$error = static function (string $code, string $slug, string $message) use (&$errors): void {
    $errors[] = ['code' => $code, 'slug' => $slug, 'message' => $message];
};
$warn = static function (string $code, string $slug, string $message) use (&$warnings): void {
    $warnings[] = ['code' => $code, 'slug' => $slug, 'message' => $message];
};

$index = hajkic_read_json($repo->indexPath());
if ($index === null) {
    fwrite(STDERR, "Dataset nije pronađen: data/trails-index.json\n");
    exit(2);
}

$indexTrails = (array) ($index['trails'] ?? []);
$stats['trails'] = count($indexTrails);

if (($index['schema_version'] ?? null) !== 1) {
    $error('schema', '-', 'Nepodržana schema_version.');
}
if (!is_array($index['facets'] ?? null)) {
    $error('facets', '-', 'Nedostaju facet podaci.');
}

$seenSlugs = [];
$seenCodes = [];
$expectedSlugs = [];

foreach ($indexTrails as $entry) {
    $stats['checked']++;
    $slug = (string) ($entry['slug'] ?? '');
    if ($slug === '' || !Hajkic\Data\TrailRepository::validSlug($slug)) {
        $error('slug', $slug, 'Neispravan slug u indeksu.');
        continue;
    }
    if (isset($seenSlugs[$slug])) {
        $error('duplicate_slug', $slug, 'Slug se pojavljuje više puta u indeksu.');
        continue;
    }
    $seenSlugs[$slug] = true;
    $expectedSlugs[$slug] = true;

    if (!empty($entry['code'])) {
        $seenCodes[(string) $entry['code']][] = $slug;
    }

    $trail = $repo->load((string) $slug);
    if ($trail === null) {
        $error('missing_trail_file', $slug, 'Nedostaje data/trails/{slug}.json.');
        continue;
    }
    if (($trail['slug'] ?? null) !== $slug) {
        $error('slug_mismatch', $slug, 'slug u fajlu se ne poklapa sa imenom.');
    }
    if (trim((string) ($trail['name'] ?? '')) === '') {
        $error('missing_name', $slug, 'Nedostaje naziv staze.');
    }
    if (($trail['schema_version'] ?? null) !== 1) {
        $error('schema', $slug, 'Nepodržana schema_version u zapisu.');
    }

    $sourceUrl = (string) ($trail['source']['canonical_url'] ?? $trail['source']['url'] ?? '');
    $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
    if (!in_array($host, ['pss.rs', 'www.pss.rs'], true)) {
        $error('source_host', $slug, 'Izvor nije pss.rs: ' . $sourceUrl);
    }

    foreach (['official_km', 'calculated_km'] as $key) {
        $value = $trail['distance'][$key] ?? null;
        if ($value !== null && (!is_numeric($value) || (float) $value < 0)) {
            $error('distance', $slug, 'Neispravna vrednost distance.' . $key);
        }
    }
    foreach (['official_gain_m', 'official_loss_m', 'calculated_gain_m', 'calculated_loss_m', 'min_m', 'max_m'] as $key) {
        $value = $trail['elevation'][$key] ?? null;
        if ($value !== null && (!is_numeric($value) || (float) $value < -500 || (float) $value > 9000)) {
            $error('elevation', $slug, 'Neispravna visinska vrednost: ' . $key);
        }
    }

    $gpx = (array) ($trail['gpx'] ?? []);
    $available = (bool) ($gpx['available'] ?? false);
    $geoPath = $repo->geojsonPath($slug);

    if ($available) {
        $geo = hajkic_read_json($geoPath);
        if ($geo === null) {
            $error('missing_geojson', $slug, 'GPX je označen kao dostupan, ali GeoJSON nedostaje.');
            continue;
        }
        if (($geo['type'] ?? null) !== 'FeatureCollection') {
            $error('geojson_type', $slug, 'GeoJSON nije FeatureCollection.');
            continue;
        }
        $stats['geojson']++;
        if ((bool) ($gpx['has_elevation'] ?? false)) {
            $stats['with_elevation']++;
        }

        $lines = 0;
        $points = 0;
        $bbox = null;
        $geoLength = 0.0;
        foreach ((array) ($geo['features'] ?? []) as $feature) {
            $kind = $feature['properties']['kind'] ?? null;
            $type = $feature['geometry']['type'] ?? null;
            $coords = $feature['geometry']['coordinates'] ?? [];

            if ($kind === 'trail') {
                if ($type !== 'LineString') {
                    $error('geojson_geometry', $slug, 'Trail feature nije LineString.');
                    continue;
                }
                $lines++;
                if (count($coords) < 2) {
                    $error('geojson_line', $slug, 'Linija ima manje od dve tačke.');
                }
                $elevations = $feature['properties']['elevations_m'] ?? [];
                if (is_array($elevations) && count($elevations) !== count($coords)) {
                    $error('geojson_elevations', $slug, 'Broj visina se ne poklapa sa brojem koordinata.');
                }
                foreach ($coords as $coord) {
                    if (!is_array($coord) || count($coord) < 2) {
                        $error('geojson_coord', $slug, 'Neispravna koordinata.');
                        continue;
                    }
                    $lon = (float) $coord[0];
                    $lat = (float) $coord[1];
                    if ($lon < -180 || $lon > 180 || $lat < -90 || $lat > 90) {
                        $error('geojson_range', $slug, 'Koordinata je van dozvoljenog opsega.');
                    }
                    $points++;
                }
                $lineBbox = Hajkic\Geo\Geo::bbox($coords);
                if ($lineBbox !== null) {
                    $bbox = $bbox === null ? $lineBbox : [
                        min($bbox[0], $lineBbox[0]),
                        min($bbox[1], $lineBbox[1]),
                        max($bbox[2], $lineBbox[2]),
                        max($bbox[3], $lineBbox[3]),
                    ];
                }
                // Each feature is a segment; sum lengths without connecting
                // segment endpoints across gaps.
                $geoLength += Hajkic\Geo\Geo::pathLengthMeters($coords) / 1000;
            } elseif ($kind === 'waypoint') {
                if ($type !== 'Point') {
                    $error('waypoint_geometry', $slug, 'Waypoint nije Point.');
                }
            }
        }

        if ($lines === 0) {
            $error('no_lines', $slug, 'GeoJSON nema nijednu liniju staze.');
        }
        if ($points < 2) {
            $error('no_points', $slug, 'GeoJSON ima premalo tačaka.');
        }

        $trailBbox = $trail['geometry']['bbox'] ?? null;
        if ($bbox === null || !is_array($trailBbox) || count($trailBbox) !== 4) {
            $error('bbox', $slug, 'Nedostaje bbox.');
        } else {
            foreach ([0, 1, 2, 3] as $i) {
                if (abs((float) $trailBbox[$i] - (float) $bbox[$i]) > 0.02) {
                    $warn('bbox_mismatch', $slug, 'Bbox u zapisu odstupa od GeoJSON-a.');
                    break;
                }
            }
        }

        $calc = (float) ($trail['distance']['calculated_km'] ?? 0);
        if ($calc > 0.5 && abs($geoLength - $calc) / $calc > 0.2) {
            $warn('calculated_distance_mismatch', $slug, sprintf('GeoJSON dužina %.2f km odstupa od izračunate %.2f km.', $geoLength, $calc));
        }
    } else {
        if (is_file($geoPath)) {
            $warn('orphan_geojson', $slug, 'Postoji GeoJSON iako GPX nije dostupan.');
        }
        if (($gpx['status'] ?? '') === 'invalid') {
            $warn('invalid_gpx', $slug, 'GPX je neispravan: ' . (string) ($gpx['error'] ?? ''));
        }
    }

    $level = $trail['data_quality']['level'] ?? null;
    if (!in_array($level, ['complete', 'partial', 'minimal'], true)) {
        $error('quality', $slug, 'Neispravan data_quality.level.');
    }
}

foreach ($seenCodes as $code => $slugs) {
    if (count($slugs) > 1) {
        $warn('duplicate_code', implode(',', $slugs), 'Ista PSS oznaka na više staza: ' . $code);
    }
}

$overview = hajkic_read_json($repo->overviewPath());
if ($overview === null) {
    $error('overview', '-', 'Nedostaje data/overview.geojson.');
} else {
    $features = (array) ($overview['features'] ?? []);
    $overviewSlugs = [];
    foreach ($features as $feature) {
        $slug = (string) ($feature['properties']['slug'] ?? '');
        if ($slug === '' || !isset($expectedSlugs[$slug])) {
            $error('overview_slug', $slug, 'Overview feature nema validan slug.');
        }
        $overviewSlugs[$slug] = true;
        if (($feature['geometry']['type'] ?? null) !== 'MultiLineString') {
            $error('overview_geometry', $slug, 'Overview geometrija nije MultiLineString.');
        }
    }
    foreach ($expectedSlugs as $slug => $_) {
        $trail = $repo->load((string) $slug);
        $hasOverview = ($trail['geometry']['overview_lines'] ?? []) !== [];
        if ($hasOverview && !isset($overviewSlugs[$slug])) {
            $error('overview_missing', $slug, 'Staza ima geometriju ali nema overview feature.');
        }
    }
}

foreach ($repo->slugs() as $slug) {
    if (!isset($expectedSlugs[$slug])) {
        $warn('orphan_trail', $slug, 'Trail JSON postoji ali nije u indeksu.');
    }
}

$declared = (array) ($index['counts'] ?? []);
foreach ([
    'trails' => $stats['trails'],
    'with_gpx' => $stats['geojson'],
    'with_elevation' => $stats['with_elevation'],
] as $key => $actual) {
    if (isset($declared[$key]) && (int) $declared[$key] !== $actual) {
        $error('count_mismatch', '-', sprintf('%s: indeks kaže %d, validacija %d.', $key, (int) $declared[$key], $actual));
    }
}

$report = [
    'validated_at' => hajkic_now(),
    'stats' => $stats,
    'errors' => $errors,
    'warnings' => $warnings,
    'ok' => $errors === [],
];

if ($jsonPath !== null) {
    hajkic_write_atomic($jsonPath, hajkic_json($report, true));
}

echo "Hajkić — validacija dataseta\n";
echo str_repeat('─', 44) . "\n";
echo sprintf("Staza u indeksu: %d\n", $stats['trails']);
echo sprintf("GeoJSON fajlova: %d\n", $stats['geojson']);
echo sprintf("Sa visinskim profilom: %d\n", $stats['with_elevation']);
echo sprintf("Grešaka: %d\n", count($errors));
echo sprintf("Upozorenja: %d\n", count($warnings));

if ($warnings !== []) {
    echo "\nUpozorenja (prvih 15):\n";
    foreach (array_slice($warnings, 0, 15) as $item) {
        echo sprintf("  [%s] %s — %s\n", $item['code'], $item['slug'], $item['message']);
    }
    if (count($warnings) > 15) {
        echo sprintf("  … još %d\n", count($warnings) - 15);
    }
}

if ($errors !== []) {
    echo "\nGreške:\n";
    foreach ($errors as $item) {
        echo sprintf("  [%s] %s — %s\n", $item['code'], $item['slug'], $item['message']);
    }
    exit(1);
}

if ($strict && $warnings !== []) {
    exit(1);
}

echo "\nDataset je validan.\n";
exit(0);
