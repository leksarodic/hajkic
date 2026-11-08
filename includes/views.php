<?php

declare(strict_types=1);

/**
 * Server-side view helpers. Client-side rendering mirrors this markup in
 * assets/js/ui.js so no-JS users still get a usable list.
 */

function hajkic_format_number(?float $value, int $decimals = 1): ?string
{
    if ($value === null) {
        return null;
    }

    $formatted = number_format($value, $decimals, ',', '.');
    if (str_contains($formatted, ',')) {
        $formatted = rtrim(rtrim($formatted, '0'), ',');
    }

    return $formatted === '' ? '0' : $formatted;
}

function hajkic_format_distance_pair(array $distance): array
{
    $official = isset($distance['official_km']) && $distance['official_km'] !== null
        ? (float) $distance['official_km'] : null;
    $calculated = isset($distance['calculated_km']) && $distance['calculated_km'] !== null
        ? (float) $distance['calculated_km'] : null;
    $value = $official ?? $calculated;

    return [
        'value' => $value,
        'text' => $value === null ? null : hajkic_format_number($value, 1),
        'calculated' => $official === null && $calculated !== null,
    ];
}

function hajkic_format_duration_minutes(?int $minutes): ?string
{
    if ($minutes === null || $minutes <= 0) {
        return null;
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    if ($hours === 0) {
        return $rest . ' min';
    }

    return $hours . ' h' . ($rest > 0 ? ' ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) . ' min' : '');
}

function hajkic_place_label(array $trail): string
{
    $parts = [];
    if (!empty($trail['mountain'])) {
        $parts[] = (string) $trail['mountain'];
    }
    if (!empty($trail['region']) && (!isset($trail['mountain']) || $trail['region'] !== $trail['mountain'])) {
        $parts[] = (string) $trail['region'];
    }

    return implode(' · ', array_unique($parts));
}

/**
 * Compact trail card used in the map sidebar and catalogue.
 */
function hajkic_trail_card(array $trail, bool $large = false): string
{
    $slug = (string) ($trail['slug'] ?? '');
    $name = (string) ($trail['name'] ?? 'Bez naziva');
    $distance = hajkic_format_distance_pair((array) ($trail['distance'] ?? []));
    $duration = $trail['duration']['text'] ?? null;
    if ($duration === null && isset($trail['duration']['minutes'])) {
        $duration = hajkic_format_duration_minutes((int) $trail['duration']['minutes']);
    }
    $difficultyKey = (string) ($trail['difficulty'] ?? 'nepoznato');
    $difficultyLabel = (string) ($trail['difficulty_label'] ?? 'Nije navedeno');
    $place = hajkic_place_label($trail);
    $hasGpx = (bool) ($trail['gpx']['available'] ?? false);
    $hasElevation = (bool) ($trail['gpx']['has_elevation'] ?? false);
    $gain = $trail['elevation']['official_gain_m'] ?? $trail['elevation']['calculated_gain_m'] ?? null;
    $gainText = $gain !== null ? hajkic_format_number((float) $gain, 0) : null;
    $calculatedGain = ($trail['elevation']['official_gain_m'] ?? null) === null
        && ($trail['elevation']['calculated_gain_m'] ?? null) !== null;

    $href = url('/staza/' . rawurlencode($slug));
    $class = 'trail-card' . ($large ? ' trail-card--large' : '');

    ob_start();
    ?>
    <a class="<?= h($class) ?>" href="<?= h($href) ?>" data-slug="<?= h($slug) ?>"
       aria-label="<?= h($name . ($place !== '' ? ' — ' . $place : '')) ?>">
        <span class="trail-card__top">
            <span class="trail-card__title-wrap">
                <span class="trail-card__title"><?= h($name) ?></span>
                <?php if ($place !== ''): ?>
                    <span class="trail-card__place"><?= h($place) ?></span>
                <?php endif; ?>
            </span>
            <span class="gpx-indicator <?= $hasGpx ? 'is-available' : 'is-missing' ?>"
                  title="<?= $hasGpx ? 'GPX dostupan' : 'GPX nije dostupan' ?>">
                <?= hajkic_icon('gpx') ?>
                <span class="sr-only"><?= $hasGpx ? 'GPX dostupan' : 'GPX nije dostupan' ?></span>
            </span>
        </span>
        <span class="trail-card__meta">
            <?php if ($distance['text'] !== null): ?>
                <span class="trail-stat">
                    <span class="trail-stat__value"><?= $distance['calculated'] ? '≈' : '' ?><?= h($distance['text']) ?></span>
                    <span class="trail-stat__unit">km</span>
                </span>
            <?php endif; ?>
            <span class="badge badge--<?= h($difficultyKey) ?>"><?= h($difficultyLabel) ?></span>
            <?php if ($duration !== null): ?>
                <span class="trail-stat trail-stat--quiet"><?= h($duration) ?></span>
            <?php endif; ?>
            <?php if ($large && $gainText !== null): ?>
                <span class="trail-stat trail-stat--quiet"><?= $calculatedGain ? '≈' : '' ?><?= h($gainText) ?> m uspona</span>
            <?php endif; ?>
            <?php if ($hasElevation): ?>
                <span class="trail-card__elevation" title="GPX sadrži visinske podatke">profil</span>
            <?php endif; ?>
        </span>
        <?php if ($large && !empty($trail['description'])): ?>
            <span class="trail-card__desc"><?= h(mb_substr((string) $trail['description'], 0, 170, 'UTF-8')) ?><?= mb_strlen((string) $trail['description'], 'UTF-8') > 170 ? '…' : '' ?></span>
        <?php endif; ?>
        <?php if ($large && !empty($trail['code'])): ?>
            <span class="trail-card__code">Oznaka <?= h((string) $trail['code']) ?></span>
        <?php endif; ?>
    </a>
    <?php
    return (string) ob_get_clean();
}

/**
 * Inline SVG preview of the real GPX geometry. No fake lines are generated
 * when geometry is missing — the caller receives null instead.
 */
function hajkic_route_svg(?array $geojson, ?array $bbox, int $width = 920, int $height = 560): ?string
{
    if ($geojson === null || !is_array($geojson['features'] ?? null) || $bbox === null) {
        return null;
    }

    $lines = [];
    foreach ($geojson['features'] as $feature) {
        if (($feature['properties']['kind'] ?? '') !== 'trail') {
            continue;
        }
        $coordinates = $feature['geometry']['coordinates'] ?? [];
        if (is_array($coordinates) && count($coordinates) >= 2) {
            $lines[] = $coordinates;
        }
    }
    if ($lines === []) {
        return null;
    }

    $minLon = (float) $bbox[0];
    $minLat = (float) $bbox[1];
    $maxLon = (float) $bbox[2];
    $maxLat = (float) $bbox[3];
    $spanLon = max(0.0001, $maxLon - $minLon);
    $spanLat = max(0.0001, $maxLat - $minLat);
    $pad = 24;
    $usableW = $width - $pad * 2;
    $usableH = $height - $pad * 2;

    $paths = [];
    foreach ($lines as $line) {
        $commands = [];
        $count = count($line);
        foreach ($line as $index => $coordinate) {
            $lon = (float) ($coordinate[0] ?? 0);
            $lat = (float) ($coordinate[1] ?? 0);
            $x = $pad + (($lon - $minLon) / $spanLon) * $usableW;
            $y = $pad + (($maxLat - $lat) / $spanLat) * $usableH;
            $commands[] = ($index === 0 ? 'M' : 'L') . round($x, 1) . ',' . round($y, 1);
        }
        if (count($commands) >= 2) {
            $paths[] = implode(' ', $commands);
        }
    }
    if ($paths === []) {
        return null;
    }

    $first = $lines[0][0];
    $lastLine = $lines[count($lines) - 1];
    $last = $lastLine[count($lastLine) - 1];
    $startX = $pad + (((float) $first[0] - $minLon) / $spanLon) * $usableW;
    $startY = $pad + (($maxLat - (float) $first[1]) / $spanLat) * $usableH;
    $endX = $pad + (((float) $last[0] - $minLon) / $spanLon) * $usableW;
    $endY = $pad + (($maxLat - (float) $last[1]) / $spanLat) * $usableH;

    ob_start();
    ?>
    <svg class="route-preview" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Prikaz stvarne GPX geometrije staze">
        <g class="route-preview__contours" aria-hidden="true">
            <path d="M-20 120c160 40 240-50 390-10s250 60 390 15 200-40 300 10"/>
            <path d="M-20 190c160 40 240-50 390-10s250 60 390 15 200-40 300 10"/>
            <path d="M-20 260c160 40 240-50 390-10s250 60 390 15 200-40 300 10"/>
            <path d="M-20 330c160 40 240-50 390-10s250 60 390 15 200-40 300 10"/>
        </g>
        <?php foreach ($paths as $path): ?>
            <path class="route-preview__casing" d="<?= h($path) ?>"/>
            <path class="route-preview__line" d="<?= h($path) ?>"/>
        <?php endforeach; ?>
        <circle class="route-preview__start" cx="<?= round($startX, 1) ?>" cy="<?= round($startY, 1) ?>" r="9"/>
        <rect class="route-preview__end" x="<?= round($endX - 7, 1) ?>" y="<?= round($endY - 7, 1) ?>" width="14" height="14" rx="2"/>
    </svg>
    <?php
    return (string) ob_get_clean();
}

/**
 * Static elevation chart for the server-rendered trail page.
 */
function hajkic_elevation_svg(?array $geojson, int $width = 880, int $height = 220): ?string
{
    if ($geojson === null || !is_array($geojson['features'] ?? null)) {
        return null;
    }

    $points = [];
    $total = 0.0;
    $previous = null;
    foreach ($geojson['features'] as $feature) {
        if (($feature['properties']['kind'] ?? '') !== 'trail') {
            continue;
        }
        $coordinates = $feature['geometry']['coordinates'] ?? [];
        $elevations = $feature['properties']['elevations_m'] ?? [];
        $firstOfSegment = true;
        foreach ($coordinates as $index => $coordinate) {
            $lon = (float) ($coordinate[0] ?? 0);
            $lat = (float) ($coordinate[1] ?? 0);
            if ($previous !== null && !$firstOfSegment) {
                $total += Hajkic\Geo\Geo::haversineMeters($previous[1], $previous[0], $lat, $lon);
            }
            $ele = $elevations[$index] ?? null;
            if ($ele !== null && is_numeric($ele)) {
                $points[] = [$total, (float) $ele];
            }
            $previous = [$lon, $lat];
            $firstOfSegment = false;
        }
    }

    if (count($points) < 2) {
        return null;
    }

    // Downsample for a light inline SVG.
    if (count($points) > 260) {
        $step = count($points) / 260;
        $sampled = [];
        for ($i = 0; $i < 260; $i++) {
            $sampled[] = $points[(int) floor($i * $step)];
        }
        $sampled[] = $points[count($points) - 1];
        $points = $sampled;
    }

    $min = min(array_column($points, 1));
    $max = max(array_column($points, 1));
    $span = max(10.0, $max - $min);
    $yMin = $min - $span * 0.12;
    $yMax = $max + $span * 0.12;
    $pad = ['left' => 44, 'right' => 12, 'top' => 14, 'bottom' => 28];
    $plotW = $width - $pad['left'] - $pad['right'];
    $plotH = $height - $pad['top'] - $pad['bottom'];
    $total = max(1.0, $total);

    $commands = [];
    foreach ($points as $index => [$distance, $ele]) {
        $x = $pad['left'] + ($distance / $total) * $plotW;
        $y = $pad['top'] + (($yMax - $ele) / ($yMax - $yMin)) * $plotH;
        $commands[] = ($index === 0 ? 'M' : 'L') . round($x, 1) . ',' . round($y, 1);
    }
    $line = implode(' ', $commands);
    $area = $line . ' L' . round($pad['left'] + $plotW, 1) . ',' . round($pad['top'] + $plotH, 1)
        . ' L' . $pad['left'] . ',' . round($pad['top'] + $plotH, 1) . ' Z';

    ob_start();
    ?>
    <svg class="profile-static" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Visinski profil staze od <?= (int) $min ?> do <?= (int) $max ?> metara">
        <line class="profile-static__grid" x1="<?= $pad['left'] ?>" y1="<?= $pad['top'] ?>" x2="<?= $width - $pad['right'] ?>" y2="<?= $pad['top'] ?>"/>
        <line class="profile-static__grid" x1="<?= $pad['left'] ?>" y1="<?= $pad['top'] + $plotH / 2 ?>" x2="<?= $width - $pad['right'] ?>" y2="<?= $pad['top'] + $plotH / 2 ?>"/>
        <line class="profile-static__grid" x1="<?= $pad['left'] ?>" y1="<?= $pad['top'] + $plotH ?>" x2="<?= $width - $pad['right'] ?>" y2="<?= $pad['top'] + $plotH ?>"/>
        <path class="profile-static__area" d="<?= h($area) ?>"/>
        <path class="profile-static__line" d="<?= h($line) ?>"/>
        <text class="profile-static__label" x="4" y="<?= $pad['top'] + 4 ?>"><?= (int) round($max) ?> m</text>
        <text class="profile-static__label" x="4" y="<?= $pad['top'] + $plotH ?>"><?= (int) round($min) ?> m</text>
        <text class="profile-static__label" x="<?= $pad['left'] ?>" y="<?= $height - 8 ?>">0</text>
        <text class="profile-static__label" x="<?= $width - $pad['right'] ?>" y="<?= $height - 8 ?>" text-anchor="end"><?= h(hajkic_format_number($total / 1000, 1) ?: '0') ?> km</text>
    </svg>
    <?php
    return (string) ob_get_clean();
}

/**
 * Schema.org graph for a trail page.
 */
function hajkic_trail_structured_data(array $trail, string $slug): array
{
    $url = hajkic_absolute_url('/staza/' . $slug);
    $description = (string) ($trail['description'] ?? '');
    if ($description === '') {
        $description = 'Planinarska staza ' . ($trail['name'] ?? '') . (!empty($trail['mountain']) ? ' na ' . $trail['mountain'] : '') . '.';
    }
    $description = mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 400, 'UTF-8');

    $trip = [
        '@type'       => 'TouristTrip',
        'name'        => (string) ($trail['name'] ?? ''),
        'description' => $description,
        'url'         => $url,
        'image'       => preg_match('#^https?://#', hajkic_og_image($slug)) ? hajkic_og_image($slug) : hajkic_absolute_url(hajkic_og_image($slug)),
        'inLanguage'  => 'sr-Latn',
        'touristType' => 'Planinarenje',
        'sameAs'      => (string) ($trail['source']['canonical_url'] ?? ''),
        'isAccessibleForFree' => true,
        'provider'    => [
            '@type' => 'Organization',
            'name'  => 'Planinarski savez Srbije',
            'url'   => 'https://pss.rs/',
        ],
        'subjectOf'   => [
            '@type' => 'WebPage',
            'url'   => $url,
        ],
    ];

    if (($trail['distance']['official_km'] ?? null) !== null) {
        $trip['distance'] = [
            '@type' => 'QuantitativeValue',
            'value' => (float) $trail['distance']['official_km'],
            'unitCode' => 'KMT',
        ];
    }
    $gain = $trail['elevation']['official_gain_m'] ?? $trail['elevation']['calculated_gain_m'] ?? null;
    if ($gain !== null) {
        $trip['additionalProperty'] = [
            '@type' => 'PropertyValue',
            'name'  => 'Ukupan uspon',
            'value' => (float) $gain,
            'unitCode' => 'MTR',
        ];
    }
    if (!empty($trail['source']['updated_at'])) {
        $trip['dateModified'] = (string) $trail['source']['updated_at'];
    }
    if (!empty($trail['geometry']['bbox']) && is_array($trail['geometry']['bbox'])) {
        $bbox = $trail['geometry']['bbox'];
        $trip['geo'] = [
            '@type' => 'GeoShape',
            'box'   => $bbox[1] . ' ' . $bbox[0] . ' ' . $bbox[3] . ' ' . $bbox[2],
        ];
    }
    if (!empty($trail['start'])) {
        $trip['itinerary'] = [
            '@type' => 'ItemList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => (string) $trail['start']],
                ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($trail['end'] ?? $trail['start'])],
            ],
        ];
    }

    $breadcrumb = [
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Hajkić', 'item' => hajkic_absolute_url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Staze', 'item' => hajkic_absolute_url('/staze')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => (string) ($trail['name'] ?? ''), 'item' => $url],
        ],
    ];

    return [
        '@context' => 'https://schema.org',
        '@graph'   => [$trip, $breadcrumb],
    ];
}

/**
 * Schema.org Dataset for the machine-readable catalogue.
 */
function hajkic_dataset_structured_data(): array
{
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'Dataset',
        'name'        => 'Hajkić — katalog planinarskih staza Srbije',
        'description' => 'Lokalni katalog planinarskih i pešačkih staza Srbije sa metapodacima iz PSS registra i opcionom GPX geometrijom. Zvanične PSS vrednosti su odvojene od izračunatih vrednosti.',
        'url'         => hajkic_absolute_url('/staze'),
        'creator'     => ['@type' => 'Organization', 'name' => 'Hajkić', 'url' => hajkic_absolute_url('/')],
        'isBasedOn'   => (string) config('source.register'),
        'keywords'    => ['planinarenje', 'Srbija', 'GPX', 'staze', 'PSS', 'OpenStreetMap'],
        'spatialCoverage' => ['@type' => 'Place', 'name' => 'Srbija'],
        'distribution' => [
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => hajkic_absolute_url('/data/trails-index.json')],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/geo+json', 'contentUrl' => hajkic_absolute_url('/data/overview.geojson')],
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => hajkic_absolute_url('/data/dataset.json')],
        ],
    ];
}


/**
 * OG image URL for live PHP or the static GitHub Pages build.
 */
function hajkic_og_image(?string $slug = null): string
{
    if ((bool) config('app.static_build', false)) {
        return $slug !== null && $slug !== ''
            ? url('/assets/og/' . rawurlencode($slug) . '.png')
            : url('/assets/og/default.png');
    }

    return $slug !== null && $slug !== ''
        ? url('/og.php?trail=' . rawurlencode($slug))
        : url('/og.php');
}
