<?php

declare(strict_types=1);

/**
 * Hajkić configuration.
 *
 * Everything here is safe to commit. Machine-specific values belong in
 * config/config.local.php (ignored by git) or in environment variables.
 */

return [
    'app' => [
        'name'          => 'Hajkić',
        'tagline'       => 'Planinarske staze Srbije',
        'description'   => 'Interaktivna mapa planinarskih staza Srbije sa GPX geometrijama, visinskim profilima i podacima Planinarskog saveza Srbije.',
        'base_url'      => getenv('HAJKIC_BASE_URL') ?: '',
        'env'           => getenv('HAJKIC_ENV') ?: 'production',
        'debug'         => filter_var(getenv('HAJKIC_DEBUG') ?: '0', FILTER_VALIDATE_BOOL),
        'timezone'      => 'Europe/Belgrade',
        'locale'        => 'sr_RS.UTF-8',
        'asset_version' => '1',
        'static_build'  => filter_var(getenv('HAJKIC_STATIC_BUILD') ?: '0', FILTER_VALIDATE_BOOL),
        'contact'       => getenv('HAJKIC_CONTACT') ?: '',
    ],

    'paths' => [
        'data'    => dirname(__DIR__) . '/data',
        'storage' => dirname(__DIR__) . '/storage',
    ],

    'source' => [
        'name'             => 'Planinarski savez Srbije',
        'short_name'       => 'PSS',
        'home'             => 'https://pss.rs/',
        'register'         => 'https://pss.rs/planinarski-objekti-i-tereni/tereni/?tip=planinarski-putevi',
        'trail_base'       => 'https://pss.rs/terenipp/',
        'archive'          => 'https://pss.rs/planinarski-objekti-i-tereni/tereni/',
        'sitemap'          => 'https://pss.rs/terenipp-sitemap.xml',
        'category_slug'    => 'planinarski-putevi',
        'category_id'      => 28,
        'category_label'   => 'Planinarski i pešački putevi',
        'categories'       => [
            'planinarski-putevi' => ['id' => 28, 'label' => 'Planinarski i pešački putevi'],
            'evropski-pesacki-putevi-u-srbiji' => ['id' => 29, 'label' => 'Evropski pešački putevi u Srbiji'],
            'planinarske-transverzale' => ['id' => 30, 'label' => 'Planinarske transverzale'],
        ],
        'disclaimer'       => 'Podaci su preuzeti iz javnog registra Planinarskog saveza Srbije. Hajkić nije zvanični proizvod PSS-a. Registar može odstupati od stanja na terenu, a GPX tačnost nije garantovana. Pre polaska proverite uslove i koristite staze na sopstvenu odgovornost.',
    ],

    'import' => [
        'user_agent'            => 'HajkicImporter/1.0 (+https://github.com/leksarodic/hajkic)',
        'delay_ms'              => 1100,
        'gpx_delay_ms'          => 650,
        'timeout_s'             => 30,
        'connect_timeout_s'     => 12,
        'retries'               => 2,
        'page_cache_ttl'        => 604800,   // 7 days
        'gpx_cache_ttl'         => 2592000,  // 30 days
        'max_page_bytes'        => 8 * 1024 * 1024,
        'max_gpx_bytes'         => 25 * 1024 * 1024,
        'max_pages'             => 60,
        'discover_sitemap'      => false,
        'simplify_overview_m'   => 130,
        'simplify_detail_m'     => 7,
        'max_detail_points'     => 4000,
        'redistribute_gpx'      => false,    // see docs/DATA.md before enabling
        'allowed_hosts'         => ['pss.rs', 'www.pss.rs'],
    ],

    'map' => [
        'center' => [44.05, 20.80],
        'zoom'   => 7,
        'min_zoom' => 5,
        'max_zoom' => 19,
        'bounds' => [[42.05, 18.55], [46.25, 23.25]],
        // UN-style country outline: Serbia with Kosovo and Metohija.
        'country_border' => 'data/geo/serbia.geojson',
        'country_labels' => [
            ['name' => 'Kosovo i Metohija', 'center' => [42.66, 21.12], 'min_zoom' => 6.5],
        ],
        // Tile providers are configuration, not code. The Serbian OSM demo
        // layer is the default because it renders Kosovo and Metohija as part
        // of Serbia, in line with the Serbian constitutional view. For serious
        // traffic, self-host this layer (see docs/DEPLOY.md) or switch the
        // default below.
        'providers' => [
            [
                'id'          => 'osm-srbija',
                'name'        => 'Ulična — Srbija',
                'url'         => 'https://tiles.openstreetmap.rs/lat/{z}/{x}/{y}.png',
                'attribution' => 'Karta: <a href="https://openstreetmap.rs/">OpenStreetMap Srbija</a>, podaci &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> saradnici',
                'max_zoom'    => 18,
                'default'     => true,
                'note'        => 'Demo tile server OpenStreetMap Srbije. Za produkciju preporučuje se sopstveni server.',
            ],
            [
                'id'          => 'topo',
                'name'        => 'Topografska',
                'url'         => 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
                'attribution' => 'Karta: &copy; <a href="https://opentopomap.org">OpenTopoMap</a> (<a href="https://creativecommons.org/licenses/by-sa/3.0/">CC-BY-SA</a>), podaci &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                'max_zoom'    => 17,
                'subdomains'  => 'abc',
                'default'     => false,
            ],
            [
                'id'          => 'osm',
                'name'        => 'Ulična — svet',
                'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> saradnici',
                'max_zoom'    => 19,
                'default'     => false,
            ],
        ],
    ],

    'ui' => [
        'results_chunk' => 36,
        'search_debounce_ms' => 150,
    ],
];
