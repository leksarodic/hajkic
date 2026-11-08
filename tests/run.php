<?php

declare(strict_types=1);

/**
 * Hajkić test runner — no PHPUnit, no Composer, no network.
 *
 * Run: php tests/run.php
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static array $failures = [];
    public static string $current = '';

    public static function test(string $name, callable $fn): void
    {
        self::$current = $name;
        try {
            $fn();
            self::$passed++;
            echo "  ✓ {$name}\n";
        } catch (Throwable $e) {
            self::$failed++;
            self::$failures[] = [$name, $e->getMessage()];
            echo "  ✗ {$name}\n      {$e->getMessage()}\n";
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(($message ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public static function true(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new RuntimeException($message ?: 'expected true, got ' . var_export($value, true));
        }
    }

    public static function false(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new RuntimeException($message ?: 'expected false, got ' . var_export($value, true));
        }
    }

    public static function null(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            throw new RuntimeException($message ?: 'expected null, got ' . var_export($value, true));
        }
    }

    public static function near(float|int|null $expected, float|int|null $actual, float $delta, string $message = ''): void
    {
        if ($expected === null || $actual === null || abs((float) $expected - (float) $actual) > $delta) {
            throw new RuntimeException(($message ? $message . ': ' : '') . "expected {$expected} ± {$delta}, got " . var_export($actual, true));
        }
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(($message ? $message . ': ' : '') . "expected to contain '{$needle}'");
        }
    }
}

function fixtureMeta(string $label, mixed $value, string $type = 'text', mixed $getValue = null): array
{
    return [
        'label'    => $label,
        'type'     => $type,
        'value'    => $value,
        'getValue' => $getValue ?? (is_string($value) ? $value : ''),
    ];
}

function fixturePost(array $overrides = []): array
{
    $meta = [
        fixtureMeta('Region', '5 - Jugozapadna Srbija', 'region'),
        fixtureMeta('Oznaka', '5-12-9'),
        fixtureMeta('Planina/predeo', 'Zlatibor', 'mountain_reg'),
        fixtureMeta('Društvo/klub', [[
            'post_title' => 'Tornik PK',
            'permalink'  => 'https://pss.rs/adresar_klubova/tornik-pk/',
            'meta'       => 'Zlatiborska 28<br>064 156 55 78<br>',
        ]], 'adresar_klubova'),
        fixtureMeta('Karta', '1:50000 Titovo Užice 3'),
        fixtureMeta('Dužina (km)', '14,5'),
        fixtureMeta('Vreme obilaska (h:min)', '5:10'),
        fixtureMeta('Početak staze', 'Jokina ćuprija (Vodice)'),
        fixtureMeta('Kraj staze', 'Jokina ćuprija (Vodice)'),
        fixtureMeta('Visinska razlika: uspon/spust (m)', '550/550'),
        fixtureMeta('Opis', 'Jokina ćuprija &ndash; Popov kolac (2,5 km)'),
        fixtureMeta('Težina staze', 'laka', 'select'),
        fixtureMeta('Uređenost', 'odlično uređena i markirana', 'select'),
        fixtureMeta('Vrsta markacije', 'propisna planinarska (crveno-bela)', 'select'),
        fixtureMeta('Značajni lokaliteti', 'vrh Jelje (1411 m), Popov kolac'),
        fixtureMeta('Smeštaj i snadbevenost', '---'),
        fixtureMeta('Napomena', 'Stazu je trasirao Šuljagić Veliša.'),
        fixtureMeta('GPX', 'https://pss.rs/wp-content/uploads/2023/03/Staza-5.gpx', 'gpx'),
        fixtureMeta('Fotografije', ['https://pss.rs/a.jpg', 'https://pss.rs/b.jpg'], 'gallery'),
        fixtureMeta('Skraćenice', '<ul><li>a.c. - asfaltni put</li><li>PD - planinarsko društvo</li></ul>', 'legend'),
        fixtureMeta('Mapa', '', 'editor'),
    ];

    return array_merge([
        'ID'              => 19094,
        'title'           => 'Staza br. 5: Jokina ćuprija – Jelje - Konjoder',
        'permalink'       => 'https://pss.rs/terenipp/staza-br-5-jokina-cuprija-jelje-konjoder/',
        'terrain_category'=> 28,
        'terrain_ids'     => [122],
        'region_ids'      => [5],
        'postmeta'        => $meta,
    ], $overrides);
}

function fixtureTrailHtml(array $post, string $canonical = 'https://pss.rs/terenipp/staza-br-5-jokina-cuprija-jelje-konjoder/'): string
{
    $payload = json_encode(['themeUrl' => 'https://pss.rs', 'post' => $post], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return <<<HTML
<!doctype html><html><head>
<link rel="canonical" href="{$canonical}" />
<meta property="og:updated_time" content="2023-03-21T20:34:00+01:00" />
<title>Test staza | PSS</title>
</head><body>
<a href="https://pss.rs/wp-content/uploads/2023/03/Staza-5.gpx" download>Preuzmi</a>
<script>var terrainsObj = {$payload};//# sourceURL=fixture</script>
</body></html>
HTML;
}

function fixtureArchiveHtml(array $posts, array $terrains = [], array $regions = [], array $types = []): string
{
    $payload = json_encode([
        'themeUrl' => 'https://pss.rs',
        'posts'    => $posts,
        'terrains' => $terrains,
        'geoRegions' => $regions,
        'types'    => $types,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return '<html><body><script>var terrainsObj = ' . $payload . ';//# sourceURL=fixture</script></body></html>';
}

function gpxXml(array $tracks, array $waypoints = [], bool $withElevation = true): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="HajkicTest" xmlns="http://www.topografix.com/GPX/1/1">';
    foreach ($waypoints as $waypoint) {
        $xml .= '<wpt lat="' . $waypoint['lat'] . '" lon="' . $waypoint['lon'] . '">';
        if (array_key_exists('ele', $waypoint) && $waypoint['ele'] !== null) {
            $xml .= '<ele>' . $waypoint['ele'] . '</ele>';
        }
        if (isset($waypoint['name'])) {
            $xml .= '<name>' . htmlspecialchars($waypoint['name'], ENT_XML1) . '</name>';
        }
        $xml .= '</wpt>';
    }
    foreach ($tracks as $track) {
        $xml .= '<trk>';
        if (isset($track['name'])) {
            $xml .= '<name>' . htmlspecialchars($track['name'], ENT_XML1) . '</name>';
        }
        foreach ($track['segments'] as $segment) {
            $xml .= '<trkseg>';
            foreach ($segment as $point) {
                $xml .= '<trkpt lat="' . $point['lat'] . '" lon="' . $point['lon'] . '">';
                if ($withElevation && array_key_exists('ele', $point) && $point['ele'] !== null) {
                    $xml .= '<ele>' . $point['ele'] . '</ele>';
                }
                $xml .= '</trkpt>';
            }
            $xml .= '</trkseg>';
        }
        $xml .= '</trk>';
    }
    $xml .= '</gpx>';

    return $xml;
}

function gpxRouteXml(array $points): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><gpx version="1.1" creator="HajkicTest" xmlns="http://www.topografix.com/GPX/1/1"><rte><name>Ruta</name>';
    foreach ($points as $point) {
        $xml .= '<rtept lat="' . $point['lat'] . '" lon="' . $point['lon'] . '"><ele>' . ($point['ele'] ?? 100) . '</ele></rtept>';
    }
    $xml .= '</rte></gpx>';

    return $xml;
}

$parser = new Hajkic\Pss\PssParser();
$context = [
    'regions' => [
        ['id' => 5, 'name' => 'Jugozapadna Srbija'],
    ],
    'terrains' => [
        ['id' => 122, 'name' => 'Zlatibor'],
    ],
    'types' => [
        '28' => ['name' => 'Planinarski i pešački putevi', 'slug' => 'planinarski-putevi'],
    ],
];

echo "Hajkić — PHP testovi\n\n";
echo "PSS parser\n";

T::test('izdvaja ugrađeni terrainsObj i kanonski URL', function () use ($parser) {
    $page = $parser->parseTrailPage(fixtureTrailHtml(fixturePost()), 'https://pss.rs/terenipp/staza-br-5-jokina-cuprija-jelje-konjoder/');
    T::true($page['has_structured']);
    T::same('2023-03-21T19:34:00Z', $page['source_updated_at']);
    T::same('https://pss.rs/terenipp/staza-br-5-jokina-cuprija-jelje-konjoder/', $page['canonical_url']);
    T::same(['https://pss.rs/wp-content/uploads/2023/03/Staza-5.gpx'], $page['gpx_urls']);
});

T::test('mapira kompletne PSS metapodatke bez mešanja zvaničnog i izračunatog', function () use ($parser, $context) {
    $page = $parser->parseTrailPage(fixtureTrailHtml(fixturePost()), 'https://pss.rs/terenipp/staza-br-5-jokina-cuprija-jelje-konjoder/');
    $mapped = $parser->mapPost($page['post'], $context, $page['gpx_urls']);

    T::same('Staza br. 5: Jokina ćuprija – Jelje - Konjoder', $mapped['name']);
    T::same('5-12-9', $mapped['code']);
    T::same('Jugozapadna Srbija', $mapped['region_name']);
    T::same('Zlatibor', $mapped['mountain']);
    T::same('laka', $mapped['difficulty']['key']);
    T::near(14.5, $mapped['distance_official_km'], 0.001);
    T::same(310, $mapped['duration']['minutes']);
    T::same('5 h 10 min', $mapped['duration']['text']);
    T::near(550, $mapped['elevation_official']['gain_m'], 0.001);
    T::near(550, $mapped['elevation_official']['loss_m'], 0.001);
    T::same('Tornik PK', $mapped['club']['name']);
    T::contains('064 156 55 78', $mapped['club']['contact']);
    T::same(2, $mapped['photos_count']);
    T::same(2, count($mapped['legend']));
    T::same('https://pss.rs/wp-content/uploads/2023/03/Staza-5.gpx', $mapped['gpx_url']);
    T::null($mapped['accommodation'], '--- mora ostati null');
    T::null($mapped['map_reference']);
});

T::test('čuva null vrednosti za nepotpune PSS zapise', function () use ($parser, $context) {
    $post = fixturePost();
    foreach ($post['postmeta'] as &$entry) {
        if (in_array($entry['label'], ['Oznaka', 'Planina/predeo', 'Dužina (km)', 'Vreme obilaska (h:min)', 'Visinska razlika: uspon/spust (m)', 'Težina staze', 'Opis', 'GPX'], true)) {
            $entry['value'] = '';
            $entry['getValue'] = '';
        }
    }
    unset($entry);
    $post['postmeta'] = array_values(array_filter($post['postmeta'], static fn ($entry) => $entry['label'] !== 'Društvo/klub'));
    $post['terrain_ids'] = [];

    $mapped = $parser->mapPost($post, $context, []);
    T::null($mapped['code']);
    T::null($mapped['mountain']);
    T::null($mapped['distance_official_km']);
    T::null($mapped['duration']['minutes']);
    T::null($mapped['elevation_official']['gain_m']);
    T::null($mapped['description']);
    T::null($mapped['gpx_url']);
    T::same('nepoznato', $mapped['difficulty']['key']);
});

T::test('ne pravi GPX URL van PSS domena', function () use ($parser) {
    $html = '<html><head><link rel="canonical" href="https://pss.rs/terenipp/test/"></head><body><a href="https://evil.example/track.gpx">x</a><a href="https://pss.rs/a.gpx">ok</a></body></html>';
    $page = $parser->parseTrailPage($html, 'https://pss.rs/terenipp/test/');
    T::same(['https://pss.rs/a.gpx'], $page['gpx_urls']);
});

T::test('parsira sitemap i lastmod datume', function () use ($parser) {
    $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://pss.rs/terenipp/a/</loc><lastmod>2026-10-08T21:44:20+00:00</lastmod></url><url><loc>https://pss.rs/o-savezu/</loc></url></urlset>';
    $entries = $parser->parseSitemap($xml);
    T::same(1, count($entries));
    T::same('https://pss.rs/terenipp/a/', $entries[0]['url']);
    T::same('2026-10-08T21:44:20Z', $entries[0]['lastmod']);
});

echo "\nDiscovery i deduplikacija\n";

T::test('gradi URL za stranice registra', function () {
    T::same('https://pss.rs/planinarski-objekti-i-tereni/tereni/?tip=planinarski-putevi', Hajkic\Pss\PssDiscovery::archiveUrl('https://pss.rs/planinarski-objekti-i-tereni/tereni/', 1, 'planinarski-putevi'));
    T::same('https://pss.rs/planinarski-objekti-i-tereni/tereni/page/3/?tip=planinarski-putevi', Hajkic\Pss\PssDiscovery::archiveUrl('https://pss.rs/planinarski-objekti-i-tereni/tereni/', 3, 'planinarski-putevi'));
});

T::test('prati paginaciju, deduplikuje ID i URL i poštuje maxPages', function () use ($parser) {
    $postA = ['ID' => 10, 'title' => 'A', 'permalink' => 'https://pss.rs/terenipp/a/'];
    $postB = ['ID' => 11, 'title' => 'B', 'permalink' => 'https://pss.rs/terenipp/b/'];
    $postC = ['ID' => 12, 'title' => 'C', 'permalink' => 'https://pss.rs/terenipp/c/'];
    $pages = [
        1 => fixtureArchiveHtml([$postA, $postB]),
        2 => fixtureArchiveHtml([$postA, $postC]),
        3 => fixtureArchiveHtml([$postA, $postC]),
    ];
    $fetched = [];
    $discovery = new Hajkic\Pss\PssDiscovery(function (int $page) use ($pages, &$fetched): ?string {
        $fetched[] = $page;
        return $pages[$page] ?? null;
    }, $parser);

    $result = $discovery->collectArchive('planinarski-putevi', 10);
    T::same([1, 2, 3], $fetched);
    T::same(3, count($result['candidates']));
    T::same(['a', 'b', 'c'], array_column($result['candidates'], 'slug'));
    T::same(3, $result['pages_fetched']);

    $limited = (new Hajkic\Pss\PssDiscovery(function (int $page) use ($pages): ?string {
        return $pages[$page] ?? null;
    }, $parser))->collectArchive('planinarski-putevi', 1);
    T::same(1, $limited['pages_fetched']);
    T::same(2, count($limited['candidates']));
});

echo "\nGPX parser i geometrija\n";

$gpxParser = new Hajkic\Geo\GpxParser();
$routeProcessor = new Hajkic\Geo\RouteProcessor();

$simpleTrack = [[
    'name' => 'Glavni trag',
    'segments' => [[
        ['lat' => 44.0000, 'lon' => 20.0000, 'ele' => 100],
        ['lat' => 44.0010, 'lon' => 20.0000, 'ele' => 110],
        ['lat' => 44.0020, 'lon' => 20.0000, 'ele' => 120],
        ['lat' => 44.0030, 'lon' => 20.0000, 'ele' => 110],
        ['lat' => 44.0040, 'lon' => 20.0000, 'ele' => 100],
    ]],
]];

T::test('parsira validan GPX i visine', function () use ($gpxParser, $simpleTrack) {
    $parsed = $gpxParser->parse(gpxXml($simpleTrack, [['lat' => 44.0, 'lon' => 20.0, 'ele' => 0, 'name' => 'Start']]));
    T::true($parsed['ok']);
    T::true($parsed['has_elevation']);
    T::same(5, $parsed['counts']['points']);
    T::same(1, $parsed['counts']['tracks']);
    T::same(1, $parsed['counts']['segments']);
    T::same(1, $parsed['counts']['waypoints']);
    T::null($parsed['waypoints'][0]['ele'], 'nulti ele waypointa je placeholder');
    T::near(44.0, $parsed['bbox'][1], 0.0001);
    T::near(20.0, $parsed['bbox'][0], 0.0001);
});

T::test('ispravno sabira distancu po segmentima bez spajanja', function () use ($gpxParser, $routeProcessor) {
    $multi = [
        ['name' => 'T1', 'segments' => [
            [['lat' => 44.0, 'lon' => 20.0, 'ele' => 100], ['lat' => 44.001, 'lon' => 20.0, 'ele' => 100]],
            [['lat' => 44.002, 'lon' => 20.0, 'ele' => 100], ['lat' => 44.003, 'lon' => 20.0, 'ele' => 100]],
        ]],
        ['name' => 'T2', 'segments' => [
            [['lat' => 44.01, 'lon' => 20.0, 'ele' => 100], ['lat' => 44.011, 'lon' => 20.0, 'ele' => 100]],
        ]],
    ];
    $parsed = $gpxParser->parse(gpxXml($multi));
    T::same(6, $parsed['counts']['points']);
    T::same(2, $parsed['counts']['tracks']);
    T::same(3, $parsed['counts']['segments']);

    $result = $routeProcessor->process($parsed, 130, 7, 4000);
    T::true($result['ok']);
    T::same(3, $result['stats']['segment_count']);
    T::near(0.333, $result['stats']['distance_km'], 0.02, 'tri segmenta po ~111 m');
    T::same(3, count(array_filter($result['detail']['features'], static fn ($f) => $f['properties']['kind'] === 'trail')));
});

T::test('handluje GPX bez visina bez izmišljanja profila', function () use ($gpxParser, $routeProcessor, $simpleTrack) {
    $parsed = $gpxParser->parse(gpxXml($simpleTrack, [], false));
    T::true($parsed['ok']);
    T::false($parsed['has_elevation']);
    $result = $routeProcessor->process($parsed);
    T::null($result['stats']['ascent_m']);
    T::null($result['stats']['min_elevation_m']);
    T::false($result['stats']['has_elevation']);
});

T::test('odbija neispravan GPX', function () use ($gpxParser) {
    T::false($gpxParser->parse('<html>nije gpx</html>')['ok']);
    T::false($gpxParser->parse('')['ok']);
    T::false($gpxParser->parse('<gpx><trk><trkseg></trkseg></trk></gpx>')['ok']);
});

T::test('podržava rte rute', function () use ($gpxParser, $routeProcessor) {
    $parsed = $gpxParser->parse(gpxRouteXml([
        ['lat' => 44.0, 'lon' => 20.0, 'ele' => 200],
        ['lat' => 44.001, 'lon' => 20.0, 'ele' => 220],
    ]));
    T::true($parsed['ok']);
    T::same(1, $parsed['counts']['tracks']);
    T::same(2, $parsed['counts']['points']);
    $result = $routeProcessor->process($parsed);
    T::true($result['ok']);
});

T::test('računa uspon i spust sa pragom šuma', function () {
    $elevation = new Hajkic\Geo\Elevation(5, 1.5);
    $result = $elevation->analyse([100, 110, 120, 110, 100]);
    T::near(20, $result['gain_m'], 5);
    T::near(20, $result['loss_m'], 5);
    T::near(100, $result['min_m'], 0.01);
    T::near(120, $result['max_m'], 0.01);
});

T::test('generiše GeoJSON sa linijama, waypointima, bbox-om i start/kraj tačkama', function () use ($gpxParser, $routeProcessor, $simpleTrack) {
    $parsed = $gpxParser->parse(gpxXml($simpleTrack, [
        ['lat' => 44.002, 'lon' => 20.0005, 'ele' => 120, 'name' => 'Vrh'],
    ]));
    $result = $routeProcessor->process($parsed);
    T::true($result['ok']);
    T::same('FeatureCollection', $result['detail']['type']);
    T::same(2, count($result['detail']['features']));
    T::same('LineString', $result['detail']['features'][0]['geometry']['type']);
    T::same('Point', $result['detail']['features'][1]['geometry']['type']);
    T::true(count($result['detail']['features'][0]['properties']['elevations_m']) >= 2);
    T::same('MultiLineString', $result['overview']['geometry']['type']);
    T::near(20.0, $result['bbox'][0], 0.0001);
    T::near(44.0, $result['start'][1], 0.0001);
    T::near(44.004, $result['end'][1], 0.0001);
    T::false($result['stats']['is_loop']);
});

T::test('prepoznaje kružnu rutu', function () use ($gpxParser, $routeProcessor) {
    $parsed = $gpxParser->parse(gpxXml([[
        'segments' => [[
            ['lat' => 44.0, 'lon' => 20.0, 'ele' => 100],
            ['lat' => 44.002, 'lon' => 20.0, 'ele' => 120],
            ['lat' => 44.0, 'lon' => 20.0, 'ele' => 100],
        ]],
    ]]));
    $result = $routeProcessor->process($parsed);
    T::true($result['stats']['is_loop']);
});

T::test('simplifikacija čuva prvu i poslednju tačku', function () {
    $points = [];
    for ($i = 0; $i <= 200; $i++) {
        $points[] = [20 + $i * 0.00001, 44 + ($i % 7 === 0 ? 0.0002 : 0)];
    }
    $simplified = Hajkic\Geo\Simplifier::simplify($points, 30, 0);
    T::true(count($simplified) < count($points));
    T::same($points[0], $simplified[0]);
    T::same($points[count($points) - 1], $simplified[count($simplified) - 1]);
});

echo "\nStvarni lokalni dataset\n";

T::test('dataset sadrži staze, GPX i GeoJSON bez izmišljenih vrednosti', function () {
    $repo = new Hajkic\Data\TrailRepository();
    $index = hajkic_read_json($repo->indexPath());
    if ($index === null) {
        throw new RuntimeException('Dataset nije prisutan; pokrenite importer.');
    }

    T::true(($index['counts']['trails'] ?? 0) >= 300, 'očekuje se najmanje 300 staza');
    T::true(($index['counts']['with_gpx'] ?? 0) >= 100, 'očekuje se najmanje 100 GPX staza');

    $slug = 'staza-br-5-jokina-cuprija-jelje-konjoder';
    $trail = $repo->load($slug);
    if ($trail === null) {
        throw new RuntimeException('Referentna staza nije u datasetu.');
    }

    T::near(14.5, $trail['distance']['official_km'], 0.01);
    T::true($trail['gpx']['available']);
    $geojson = $repo->loadGeojson($slug);
    T::true($geojson !== null);
    $line = null;
    foreach ($geojson['features'] as $feature) {
        if (($feature['properties']['kind'] ?? '') === 'trail') {
            $line = $feature;
            break;
        }
    }
    T::true($line !== null);
    $first = $line['geometry']['coordinates'][0];
    T::true($first[0] >= $trail['geometry']['bbox'][0] - 0.01 && $first[0] <= $trail['geometry']['bbox'][2] + 0.01);
    T::true($first[1] >= $trail['geometry']['bbox'][1] - 0.01 && $first[1] <= $trail['geometry']['bbox'][3] + 0.01);
    T::true(count($line['geometry']['coordinates']) >= 2);
    T::true(($line['properties']['elevations_m'][0] ?? null) !== null);
});

T::test('nepotpuni zapisi koriste null, ne nulu', function () {
    $repo = new Hajkic\Data\TrailRepository();
    $trail = $repo->load('mitrovac-predov-krst');
    if ($trail === null) {
        throw new RuntimeException('Nepotpuni referentni zapis nije prisutan.');
    }
    T::null($trail['distance']['official_km']);
    T::null($trail['distance']['calculated_km']);
    T::null($trail['elevation']['official_gain_m']);
    T::same('minimal', $trail['data_quality']['level']);
});

echo "\n";
echo "Rezultat: " . T::$passed . " prošlo, " . T::$failed . " palo.\n";
if (T::$failed > 0) {
    echo "\nNeuspesi:\n";
    foreach (T::$failures as [$name, $message]) {
        echo " - {$name}: {$message}\n";
    }
    exit(1);
}
exit(0);
