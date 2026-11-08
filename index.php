<?php

declare(strict_types=1);

/**
 * Hajkić front controller.
 *
 * Works on ordinary shared PHP hosting: no database, no Node.js runtime,
 * no build step. Trail data is read from static JSON files in /data.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$requestPath = static function (): string {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

    $basePath = (string) (parse_url((string) config('app.base_url', ''), PHP_URL_PATH) ?: '');
    if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath));
    }

    $path = '/' . trim($path, '/');

    return $path === '/' ? '/' : rtrim($path, '/');
};

$renderOnly = defined('HAJKIC_RENDER_ONLY') && HAJKIC_RENDER_ONLY;
$path = $requestPath();

if ($path === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    exit;
}

if ($path === '/robots.txt') {
    require __DIR__ . '/robots.php';
    exit;
}

if ($path === '/llms.txt') {
    require __DIR__ . '/llms.php';
    exit;
}

$repo = new Hajkic\Data\TrailRepository();
$indexMeta = hajkic_read_json($repo->dataDir() . '/trails-index.json');

/**
 * Read initial filter state from query parameters.
 *
 * @return array<string,mixed>
 */
$initialFromRequest = static function (?string $forcedTrail = null): array {
    $difficulty = $_GET['difficulty'] ?? '';
    if (is_array($difficulty)) {
        $difficulty = implode(',', array_map('strval', $difficulty));
    }
    $difficulty = array_values(array_filter(array_map(
        static fn ($value) => preg_replace('/[^a-z_]/', '', (string) $value) ?? '',
        explode(',', (string) $difficulty)
    ), static fn ($value) => $value !== ''));

    $gpx = (string) ($_GET['gpx'] ?? '');
    if (!in_array($gpx, ['', 'yes', 'no'], true)) {
        $gpx = '';
    }

    $clean = static function (string $key, int $max = 90) {
        $value = $_GET[$key] ?? '';
        if (is_array($value)) {
            $value = '';
        }
        $value = trim((string) $value);
        return mb_substr($value, 0, $max, 'UTF-8');
    };

    return [
        'q'          => $clean('q', 120),
        'region'     => $clean('region', 90),
        'mountain'   => $clean('mountain', 90),
        'difficulty' => $difficulty,
        'dist'       => $clean('dist', 20),
        'elev'       => $clean('elev', 20),
        'gpx'        => $gpx,
        'trail'      => $forcedTrail,
    ];
};

$render = static function (string $view, array $vars) use ($indexMeta): string {
    extract($vars, EXTR_SKIP);
    ob_start();
    require HAJKIC_ROOT . '/views/' . $view . '.php';
    return (string) ob_get_clean();
};

$renderPage = static function (array $vars) use ($render): void {
    $content = $render('pages/' . $vars['view'], $vars);
    unset($vars['view']);
    extract($vars, EXTR_SKIP);
    require HAJKIC_ROOT . '/views/layout.php';
};

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

if ($path === '/') {
    $trailQuery = $_GET['trail'] ?? null;
    $trailSlug = is_string($trailQuery) ? hajkic_slugify($trailQuery, 90) : '';
    $selectedTrailData = $trailSlug !== '' ? $repo->load($trailSlug) : null;
    $initial = $initialFromRequest($selectedTrailData !== null ? $trailSlug : null);

    $pageTitle = 'Interaktivna mapa planinarskih staza Srbije';
    $metaDescription = 'Istraži planinarske staze Srbije na interaktivnoj mapi. Stvarna GPX geometrija, visinski profili, filteri po planini, težini i dužini.';
    $canonicalPath = '/';
    $structuredData = null;
    $ogImage = hajkic_og_image();
    $ogType = 'website';

    if ($selectedTrailData !== null) {
        $trailDescription = (string) ($selectedTrailData['description'] ?? '');
        $pageTitle = (string) $selectedTrailData['name'] . (!empty($selectedTrailData['mountain']) && $selectedTrailData['mountain'] !== $selectedTrailData['name'] ? ' — ' . $selectedTrailData['mountain'] : '');
        $metaDescription = mb_substr(preg_replace('/\s+/u', ' ', $trailDescription) ?? $trailDescription, 0, 180, 'UTF-8');
        if ($metaDescription === '') {
            $metaDescription = 'Planinarska staza ' . $selectedTrailData['name'] . ' — mapa, GPX i visinski profil.';
        }
        $canonicalPath = '/staza/' . $trailSlug;
        $structuredData = hajkic_trail_structured_data($selectedTrailData, $trailSlug);
        $ogImage = hajkic_og_image($trailSlug);
        $ogType = 'article';
    }

    $renderPage([
        'view'            => 'map',
        'page'            => 'map',
        'useMap'          => true,
        'bodyClass'       => 'map-page',
        'pageTitle'       => $pageTitle,
        'metaDescription' => $metaDescription,
        'canonicalPath'   => $canonicalPath,
        'indexMeta'       => $indexMeta,
        'initial'         => $initial,
        'selectedTrail'   => $selectedTrailData !== null ? $trailSlug : null,
        'structuredData'  => $structuredData,
        'ogImage'         => $ogImage,
        'ogType'          => $ogType,
    ]);
    if ($renderOnly) { return; }
    exit;
}

if ($path === '/staze') {
    $initial = $initialFromRequest();
    $renderPage([
        'view'            => 'catalogue',
        'page'            => 'catalogue',
        'useMap'          => false,
        'bodyClass'       => 'catalogue-page',
        'pageTitle'       => 'Katalog planinarskih staza Srbije',
        'metaDescription' => 'Pretraži sve planinarske i pešačke staze Srbije po planini, regionu, težini, dužini i dostupnosti GPX-a.',
        'canonicalPath'   => '/staze',
        'indexMeta'       => $indexMeta,
        'initial'         => $initial,
        'selectedTrail'   => null,
        'structuredData'  => hajkic_dataset_structured_data(),
        'ogImage'         => hajkic_og_image(),
        'ogType'          => 'website',
    ]);
    if ($renderOnly) { return; }
    exit;
}

if ($path === '/o-projektu') {
    $renderPage([
        'view'            => 'about',
        'page'            => 'about',
        'useMap'          => false,
        'bodyClass'       => 'about-page',
        'pageTitle'       => 'O projektu Hajkić',
        'metaDescription' => 'Kako Hajkić koristi podatke PSS registra, šta su zvanične a šta izračunate vrednosti, i zašto GPX tačnost nije garancija.',
        'canonicalPath'   => '/o-projektu',
        'indexMeta'       => $indexMeta,
        'initial'         => $initialFromRequest(),
        'selectedTrail'   => null,
        'structuredData'  => hajkic_dataset_structured_data(),
        'ogImage'         => hajkic_og_image(),
        'ogType'          => 'website',
    ]);
    if ($renderOnly) { return; }
    exit;
}

if (str_starts_with($path, '/staza/')) {
    $slug = hajkic_slugify(basename($path), 90);
    $trail = $slug !== '' ? $repo->load($slug) : null;

    if ($trail === null) {
        http_response_code(404);
        $renderPage([
            'view'            => 'not-found',
            'page'            => 'error',
            'useMap'          => false,
            'useApp'          => false,
            'bodyClass'       => 'error-page',
            'pageTitle'       => 'Staza nije pronađena',
            'metaDescription' => 'Tražena staza ne postoji u lokalnom katalogu.',
            'canonicalPath'   => '/staze',
            'robots'          => 'noindex,follow',
            'indexMeta'       => $indexMeta,
            'initial'         => $initialFromRequest(),
            'selectedTrail'   => null,
            'ogImage'         => hajkic_og_image(),
            'ogType'          => 'website',
        ]);
        if ($renderOnly) { return; }
        exit;
    }

    $geojson = $repo->loadGeojson($slug);
    $related = [];
    if (is_array($indexMeta['trails'] ?? null)) {
        foreach ($indexMeta['trails'] as $candidate) {
            if (($candidate['slug'] ?? null) === $slug) {
                continue;
            }
            $sameMountain = !empty($trail['mountain']) && ($candidate['mountain'] ?? null) === $trail['mountain'];
            $sameRegion = !empty($trail['region']) && ($candidate['region'] ?? null) === $trail['region'];
            if ($sameMountain || $sameRegion) {
                $related[] = $candidate;
            }
        }
        usort($related, static function (array $a, array $b) use ($trail): int {
            $aMountain = ($a['mountain'] ?? null) === ($trail['mountain'] ?? null) ? 0 : 1;
            $bMountain = ($b['mountain'] ?? null) === ($trail['mountain'] ?? null) ? 0 : 1;
            return $aMountain <=> $bMountain;
        });
        $related = array_slice($related, 0, 3);
    }

    $description = (string) ($trail['description'] ?? '');
    $metaDescription = mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 180, 'UTF-8');
    if ($metaDescription === '') {
        $metaDescription = 'Planinarska staza ' . $trail['name'] . (!empty($trail['mountain']) ? ' na ' . $trail['mountain'] : '') . ' — opis, GPX i visinski profil.';
    }

    $renderPage([
        'view'            => 'trail',
        'page'            => 'trail',
        'useMap'          => false,
        'useApp'          => false,
        'bodyClass'       => 'trail-page-body',
        'pageTitle'       => (string) $trail['name'] . (!empty($trail['mountain']) && $trail['mountain'] !== $trail['name'] ? ' — ' . $trail['mountain'] : ''),
        'metaDescription' => $metaDescription,
        'canonicalPath'   => '/staza/' . $slug,
        'robots'          => 'index,follow,max-image-preview:large',
        'indexMeta'       => $indexMeta,
        'initial'         => [],
        'selectedTrail'   => null,
        'trail'           => $trail,
        'geojson'         => $geojson,
        'related'         => $related,
        'structuredData'  => hajkic_trail_structured_data($trail, $slug),
        'ogImage'         => hajkic_og_image($slug),
        'ogType'          => 'article',
    ]);
    if ($renderOnly) { return; }
    exit;
}

// ---------------------------------------------------------------------------
// 404
// ---------------------------------------------------------------------------

http_response_code(404);
$renderPage([
    'view'            => 'not-found',
    'page'            => 'error',
    'useMap'          => false,
    'bodyClass'       => 'error-page',
    'pageTitle'       => 'Stranica nije pronađena',
    'metaDescription' => 'Tražena stranica ne postoji.',
    'canonicalPath'   => '/',
    'robots'          => 'noindex,follow',
    'indexMeta'       => $indexMeta,
    'initial'         => $initialFromRequest(),
    'selectedTrail'   => null,
    'ogImage'         => hajkic_og_image(),
    'ogType'          => 'website',
]);
