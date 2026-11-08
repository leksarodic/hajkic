<?php
/** @var string $page */
/** @var string $pageTitle */
/** @var string $metaDescription */
/** @var string $canonicalPath */
/** @var string $content */
/** @var bool $useMap */
/** @var array<string,mixed>|null $indexMeta */
/** @var array<string,mixed> $initial */
/** @var string|null $selectedTrail */
/** @var string $bodyClass */
/** @var bool $useApp */
/** @var string|null $ogImage */
/** @var string $ogType */
/** @var string $robots */

$useApp = $useApp ?? true;
$ogType = $ogType ?? 'website';
$robots = $robots ?? 'index,follow,max-image-preview:large';
$ogImageValue = $ogImage ?? hajkic_og_image();
$ogImageUrl = preg_match('#^https?://#', $ogImageValue) ? $ogImageValue : hajkic_absolute_url($ogImageValue);
$jsonIndex = hajkic_absolute_url('/data/trails-index.json');
$siteName = (string) config('app.name', 'Hajkić');
$counts = (array) ($indexMeta['counts'] ?? []);
$baseUrl = rtrim(url('/'), '/') . '/';
$canonical = hajkic_absolute_url($canonicalPath ?? '/');
$fullTitle = isset($pageTitle) && $pageTitle !== ''
    ? $pageTitle . ' | ' . $siteName
    : $siteName . ' — ' . (string) config('app.tagline', 'Planinarske staze Srbije');

$clientConfig = [
    'base'      => $baseUrl,
    'page'      => $page,
    'initial'   => $initial ?? [],
    'selected'  => $selectedTrail,
    'map'       => config('map'),
    'ui'        => config('ui'),
    'trailBase' => url('/staza/'),
    'dataBase'  => url('/data/'),
    'source'    => [
        'name' => config('source.name'),
        'url'  => config('source.register'),
        'disclaimer' => config('source.disclaimer'),
    ],
    'counts'    => $counts,
    'strings'   => [
        'results'      => 'staza',
        'noResults'    => 'Nema staza koje odgovaraju filterima.',
        'loading'      => 'Učitavanje…',
        'error'        => 'Podaci trenutno nisu dostupni.',
        'gpxAvailable' => 'GPX dostupan',
        'gpxMissing'   => 'GPX nije dostupan',
    ],
];
?>
<!doctype html>
<html lang="sr-Latn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f0f5ef">
    <title><?= h($fullTitle) ?></title>
    <meta name="description" content="<?= h($metaDescription ?? (string) config('app.description')) ?>">
    <meta name="robots" content="<?= h($robots) ?>">
    <link rel="canonical" href="<?= h($canonical) ?>">
    <link rel="alternate" type="application/json" href="<?= h($jsonIndex) ?>" title="Hajkić trail index (JSON)">
    <link rel="alternate" type="text/plain" href="<?= h(hajkic_absolute_url('/llms.txt')) ?>" title="Hajkić for AI assistants">
    <meta property="og:type" content="<?= h($ogType) ?>">
    <meta property="og:site_name" content="<?= h($siteName) ?>">
    <meta property="og:locale" content="sr_RS">
    <meta property="og:title" content="<?= h($fullTitle) ?>">
    <meta property="og:description" content="<?= h($metaDescription ?? (string) config('app.description')) ?>">
    <meta property="og:url" content="<?= h($canonical) ?>">
    <meta property="og:image" content="<?= h($ogImageUrl) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= h($fullTitle) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h($fullTitle) ?>">
    <meta name="twitter:description" content="<?= h($metaDescription ?? (string) config('app.description')) ?>">
    <meta name="twitter:image" content="<?= h($ogImageUrl) ?>">
    <link rel="icon" href="<?= h(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= h(asset('assets/fonts/instrument-serif-normal-400-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= h(asset('assets/fonts/inter-tight-normal-300_700-latin.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= h(asset('assets/css/app.css')) ?>">
    <?php if ($useMap): ?>
        <link rel="stylesheet" href="<?= h(asset('assets/vendor/leaflet.css')) ?>">
        <link rel="stylesheet" href="<?= h(asset('assets/vendor/MarkerCluster.css')) ?>">
        <link rel="stylesheet" href="<?= h(asset('assets/vendor/MarkerCluster.Default.css')) ?>">
    <?php endif; ?>
    <script>
        window.HAJKIC = <?= json_encode($clientConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) ?>;
    </script>
</head>
<body class="page-<?= h($page) ?> <?= h($bodyClass ?? '') ?>">
<a class="skip-link" href="#main">Preskoči na sadržaj</a>

<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="<?= h(url('/')) ?>" aria-label="Hajkić — početna">
            <svg class="brand__mark" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                <path d="M4.5 30.5 15 9l7.2 12.4L26 16l9.5 14.5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 30.5c5.2-3.6 7.7-3.6 11.2-.4 3.3 3 5.6 2.9 10.8-.5" stroke="#c0662f" stroke-width="2.1" stroke-linecap="round" stroke-dasharray="1.6 5.2"/>
            </svg>
            <span class="brand__word">Hajkić</span>
            <span class="brand__tag">staze Srbije</span>
        </a>

        <nav class="site-nav" id="site-nav" aria-label="Glavna navigacija">
            <a href="<?= h(url('/')) ?>" class="<?= $page === 'map' ? 'is-active' : '' ?>">Mapa</a>
            <a href="<?= h(url('/staze')) ?>" class="<?= in_array($page, ['catalogue', 'trail'], true) ? 'is-active' : '' ?>">Staze</a>
            <a href="<?= h(url('/o-projektu')) ?>" class="<?= $page === 'about' ? 'is-active' : '' ?>">O projektu</a>
        </nav>

        <div class="site-header__actions">
            <button type="button" class="header-search" data-search-trigger aria-label="Pretraži staze">
                <?= hajkic_icon('search') ?>
                <span>Pretraga</span>
                <kbd>⌘ K</kbd>
            </button>
            <button type="button" class="icon-btn menu-toggle" id="menu-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Meni">
                <?= hajkic_icon('menu') ?>
            </button>
        </div>
    </div>
</header>

<?= $content ?>

<?php if ($page !== 'map'): ?>
<footer class="site-footer">
    <div class="site-footer__grid">
        <div class="site-footer__brand">
            <a class="brand brand--footer" href="<?= h(url('/')) ?>">
                <svg class="brand__mark" viewBox="0 0 40 40" fill="none" aria-hidden="true">
                    <path d="M4.5 30.5 15 9l7.2 12.4L26 16l9.5 14.5" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M9 30.5c5.2-3.6 7.7-3.6 11.2-.4 3.3 3 5.6 2.9 10.8-.5" stroke="#c0662f" stroke-width="2.1" stroke-linecap="round" stroke-dasharray="1.6 5.2"/>
                </svg>
                <span class="brand__word">Hajkić</span>
            </a>
            <p>Nezavisan vodič kroz planinarske staze Srbije. Bez reklama, bez praćenja, bez lažnih podataka.</p>
        </div>
        <nav class="site-footer__nav" aria-label="Navigacija u podnožju">
            <div>
                <h2>Istraži</h2>
                <a href="<?= h(url('/')) ?>">Mapa staza</a>
                <a href="<?= h(url('/staze')) ?>">Katalog staza</a>
                <a href="<?= h(url('/o-projektu')) ?>">O projektu</a>
            </div>
            <div>
                <h2>Izvor</h2>
                <a href="<?= h((string) config('source.register')) ?>" target="_blank" rel="noopener">PSS registar terena</a>
                <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>
                <a href="https://opentopomap.org" target="_blank" rel="noopener">OpenTopoMap</a>
            </div>
        </nav>
    </div>
    <div class="site-footer__bottom">
        <p>© <?= date('Y') ?> Hajkić. Podaci o stazama: Planinarski savez Srbije. Hajkić nije zvanični proizvod PSS-a.</p>
        <p>Staze mogu biti izmenjene ili neobeležene. Proverite uslove pre polaska i krećite se na sopstvenu odgovornost.</p>
    </div>
</footer>
<?php endif; ?>

<script>
    document.getElementById('menu-toggle')?.addEventListener('click', function () {
        const nav = document.getElementById('site-nav');
        const open = nav.classList.toggle('is-open');
        this.setAttribute('aria-expanded', String(open));
    });
</script>

<?php if ($useMap): ?>
    <script src="<?= h(asset('assets/vendor/leaflet.js')) ?>"></script>
    <script src="<?= h(asset('assets/vendor/leaflet.markercluster.js')) ?>"></script>
<?php endif; ?>
<?php if ($useApp): ?>
    <script type="module" src="<?= h(asset('assets/js/app.js')) ?>"></script>
<?php endif; ?>
<?php if (isset($structuredData)): ?>
    <script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
</body>
</html>
