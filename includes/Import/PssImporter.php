<?php

declare(strict_types=1);

namespace Hajkic\Import;

use Hajkic\Data\CatalogueBuilder;
use Hajkic\Data\TrailRepository;
use Hajkic\Geo\GpxParser;
use Hajkic\Geo\RouteProcessor;
use Hajkic\Http\HttpClient;
use Hajkic\Pss\PssParser;
use Throwable;

/**
 * Orchestrates discovery, extraction, GPX processing and catalogue generation.
 */
final class PssImporter
{
    private const PARSER_VERSION = 1;
    private const RAW_GPX_DIR = HAJKIC_ROOT . '/storage/gpx';

    private HttpClient $http;
    private PssParser $pss;
    private GpxParser $gpx;
    private RouteProcessor $routes;
    private TrailRepository $repo;
    private CatalogueBuilder $catalogue;

    /** @var callable(string):void */
    private $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $message): void {
            echo '[' . gmdate('H:i:s') . '] ' . $message . PHP_EOL;
        };

        $this->http = new HttpClient([
            'cache_dir'       => HAJKIC_ROOT . '/storage/cache/http',
            'user_agent'      => (string) config('import.user_agent'),
            'timeout_s'       => (int) config('import.timeout_s'),
            'connect_timeout_s' => (int) config('import.connect_timeout_s'),
            'retries'         => (int) config('import.retries'),
            'allowed_hosts'   => (array) config('import.allowed_hosts'),
        ]);
        $this->pss = new PssParser();
        $this->gpx = new GpxParser();
        $this->routes = new RouteProcessor();
        $this->repo = new TrailRepository();
        $this->catalogue = new CatalogueBuilder($this->repo);
    }

    public function run(ImportOptions $options): array
    {
        $runAt = hajkic_now();
        $started = microtime(true);
        $dataExists = is_file($this->repo->indexPath());
        $mode = $options->dryRun
            ? 'dry-run'
            : ($options->slug !== null ? 'single' : ($options->incremental && $dataExists ? 'incremental' : 'full'));

        $report = new ImportReport($mode, $runAt);
        $lock = new ImportLock();

        $this->log('Hajkić uvoz — režim: ' . $mode);
        $lock->acquire();

        try {
            [$candidates, $context, $discovery] = $this->discover($options, $report);
            $report->set('discovered', $discovery);

            if ($options->limit > 0) {
                $candidates = array_slice($candidates, 0, $options->limit);
                $this->log('Ograničenje: obrađujem ' . count($candidates) . ' staza.');
            }

            if ($candidates === []) {
                throw new \RuntimeException('Nijedna staza nije pronađena. Proverite mrežu ili filter.');
            }

            $keepSlugs = [];
            $seenIds = [];
            $seenSlugs = [];
            $gpxDownloads = 0;
            $budgetExhausted = false;

            foreach ($candidates as $index => $candidate) {
                $position = $index + 1;
                $total = count($candidates);
                $candidateSlug = (string) ($candidate['slug'] ?? 'staza');
                $this->log(sprintf('[%d/%d] %s', $position, $total, $candidateSlug));

                try {
                    if ($budgetExhausted || ($options->gpxLimit > 0 && $gpxDownloads >= $options->gpxLimit)) {
                        $budgetExhausted = true;
                    }

                    $result = $this->processCandidate(
                        $candidate,
                        $context,
                        $options,
                        $report,
                        $seenIds,
                        $seenSlugs,
                        $gpxDownloads,
                        $budgetExhausted,
                        $position,
                        $total
                    );

                    if ($result['slug'] !== null) {
                        $keepSlugs[$result['slug']] = true;
                    }
                } catch (Throwable $e) {
                    $report->increment('failed');
                    $report->issue('error', 'trail_failed', $e->getMessage(), $candidateSlug, (string) ($candidate['url'] ?? ''));
                    $this->log('  GREŠKA: ' . $e->getMessage());
                }
            }

            if ($options->prune && !$options->dryRun && $options->limit === 0 && $options->slug === null && $options->incremental === false) {
                $pruned = $this->prune($keepSlugs);
                $report->set('pruned', $pruned);
                if ($pruned > 0) {
                    $this->log('Uklonjeno staza kojih više nema u registru: ' . $pruned);
                }
            }

            if (!$options->dryRun) {
                $built = $this->catalogue->build(true);
                $report->set('catalogue', $built['counts']);
                $this->log(sprintf(
                    'Katalog: %d staza, %d sa GPX-om, %d sa visinama.',
                    $built['counts']['trails'],
                    $built['counts']['with_gpx'],
                    $built['counts']['with_elevation']
                ));
            }

            $report->set('duration_s', round(microtime(true) - $started, 1));
            $data = $report->finish();

            if (!$options->dryRun) {
                hajkic_write_atomic($this->repo->dataDir() . '/import-report.json', $report->toJson());
            }
            if ($options->reportPath !== null) {
                hajkic_write_atomic($options->reportPath, $report->toJson());
            }

            $this->printSummary($data);

            return $data;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, true> $seenIds
     * @param array<string, true> $seenSlugs
     * @return array{slug:?string}
     */
    private function processCandidate(
        array $candidate,
        array $context,
        ImportOptions $options,
        ImportReport $report,
        array &$seenIds,
        array &$seenSlugs,
        int &$gpxDownloads,
        bool $budgetExhausted,
        int $position,
        int $total
    ): array {
        $url = (string) $candidate['url'];
        $isArchiveCandidate = ($candidate['source'] ?? 'archive') !== 'sitemap';

        $pageTtl = $options->incremental ? 0 : (int) config('import.page_cache_ttl', 604800);
        if ($options->force || $options->refresh) {
            $pageTtl = 0;
        }

        $response = $this->http->get($url, [
            'kind'  => 'page',
            'ttl'   => $pageTtl,
            'force' => $options->force || $options->refresh,
        ]);

        if (!$response->ok()) {
            $message = $response->error ?? ('HTTP ' . $response->status);
            $report->increment('failed');
            $report->issue('error', 'page_fetch_failed', $message, $candidate['slug'] ?? null, $url);
            $this->log('  stranica nedostupna: ' . $message);
            return ['slug' => null];
        }

        $parsedPage = $this->pss->parseTrailPage($response->body, $url);
        $post = $parsedPage['post'];

        if (!is_array($post) || empty($post['postmeta'])) {
            $report->increment('skipped');
            $report->data['discovered']['not_trails']++;
            if ($isArchiveCandidate) {
                $report->issue('warning', 'not_a_trail_page', 'Stranica nema strukturu staze.', $candidate['slug'] ?? null, $url);
            }
            $this->log('  preskočeno: nije stranica staze');
            return ['slug' => null];
        }

        $categoryId = isset($post['terrain_category']) ? (string) $post['terrain_category'] : null;
        $allowedCategoryIds = [];
        foreach ((array) config('source.categories', []) as $categoryConfig) {
            if (isset($categoryConfig['id'])) {
                $allowedCategoryIds[] = (string) $categoryConfig['id'];
            }
        }
        if ($allowedCategoryIds !== [] && !in_array((string) $categoryId, $allowedCategoryIds, true)) {
            $report->increment('skipped');
            $report->data['discovered']['not_trails']++;
            $this->log('  preskočeno: kategorija ' . ($categoryId ?? '?'));
            return ['slug' => null];
        }

        $canonical = $parsedPage['canonical_url'] ?? $url;
        if ($canonical === null || !str_contains((string) $canonical, '/terenipp/')) {
            $canonical = $url;
        }
        $slug = hajkic_slug_from_url($canonical) ?? (string) ($candidate['slug'] ?? '');
        if ($slug === '' || !TrailRepository::validSlug($slug)) {
            $report->increment('failed');
            $report->issue('error', 'invalid_slug', 'Ne mogu da izvedem ispravan slug.', null, $canonical);
            return ['slug' => null];
        }

        $pssId = isset($post['ID']) ? (string) $post['ID'] : null;

        if ($pssId !== null && isset($seenIds[$pssId])) {
            $report->increment('skipped');
            $report->issue('info', 'duplicate_post', 'Dupli PSS zapis u istom prolazu.', $slug, $canonical);
            $this->log('  preskočeno: duplikat PSS ID ' . $pssId);
            return ['slug' => null];
        }

        $existing = $this->repo->load($slug);
        $existingPssId = $existing['source']['pss_id'] ?? null;
        if ($existing !== null && $existingPssId !== null && $pssId !== null && (string) $existingPssId !== $pssId) {
            $report->increment('failed');
            $report->issue('error', 'slug_collision', 'Slug je već zauzet drugim PSS zapisom.', $slug, $canonical);
            $this->log('  GREŠKA: slug kolizija');
            return ['slug' => null];
        }

        if (isset($seenSlugs[$slug])) {
            $report->increment('skipped');
            $report->issue('info', 'duplicate_canonical', 'Isti kanonski URL u istom prolazu.', $slug, $canonical);
            return ['slug' => null];
        }

        $seenIds[$pssId ?? $slug] = true;
        $seenSlugs[$slug] = true;

        $mapped = $this->pss->mapPost($post, $context, $parsedPage['gpx_urls']);
        if ($mapped['name'] === '') {
            $mapped['name'] = (string) ($candidate['title'] ?? $slug);
        }

        $sourceUpdatedAt = $parsedPage['source_updated_at']
            ?? ($existing['source']['updated_at'] ?? null);
        $publishedAt = $parsedPage['published_at']
            ?? ($existing['source']['published_at'] ?? null);

        $gpxResult = $this->handleGpx(
            $slug,
            $mapped,
            $existing,
            $options,
            $report,
            $gpxDownloads,
            $budgetExhausted,
            $response->notModified
        );

        $official = $mapped['elevation_official'];
        $calculated = $gpxResult['stats'];
        $distanceOfficial = $mapped['distance_official_km'];
        $distanceCalculated = $calculated['distance_km'] ?? null;
        $gainOfficial = $official['gain_m'] ?? null;
        $lossOfficial = $official['loss_m'] ?? null;
        $gainCalculated = $calculated['ascent_m'] ?? null;
        $lossCalculated = $calculated['descent_m'] ?? null;

        if ($distanceOfficial !== null && $distanceCalculated !== null && $distanceOfficial > 0) {
            $difference = abs($distanceOfficial - $distanceCalculated) / $distanceOfficial;
            if ($difference > 0.25 && abs($distanceOfficial - $distanceCalculated) > 2.0) {
                $report->issue(
                    'warning',
                    'distance_discrepancy',
                    sprintf('Zvanična dužina %.1f km se razlikuje od GPX dužine %.1f km.', $distanceOfficial, $distanceCalculated),
                    $slug,
                    $canonical
                );
            }
        }
        if ($gainOfficial !== null && $gainCalculated !== null && $gainOfficial > 50) {
            $difference = abs($gainOfficial - $gainCalculated) / $gainOfficial;
            if ($difference > 0.4) {
                $report->issue(
                    'warning',
                    'elevation_discrepancy',
                    sprintf('Zvanični uspon %d m se razlikuje od izračunatog %d m.', $gainOfficial, $gainCalculated),
                    $slug,
                    $canonical
                );
            }
        }

        $quality = $this->dataQuality($mapped, $gpxResult, $distanceOfficial, $distanceCalculated);
        foreach ($quality['issues'] as $qualityIssue) {
            $level = in_array($qualityIssue, ['invalid_gpx', 'gpx_download_error'], true) ? 'error' : 'warning';
            $report->issue($level, $qualityIssue, $this->qualityMessage($qualityIssue), $slug, $canonical);
        }

        $now = hajkic_now();
        $trail = [
            'schema_version' => CatalogueBuilder::SCHEMA_VERSION,
            'slug'           => $slug,
            'name'           => $mapped['name'],
            'code'           => $mapped['code'],
            'category'       => [
                'key'   => $mapped['category_key'],
                'label' => $mapped['category_label'],
            ],
            'pss'            => [
                'post_id'      => $mapped['pss_id'],
                'category_id'  => $mapped['category_id'] !== null ? (int) $mapped['category_id'] : null,
                'region_ids'   => $mapped['region_id'] !== null ? [(int) $mapped['region_id']] : [],
                'mountain_ids' => $mapped['mountain_ids'],
            ],
            'region'         => $mapped['region_name'],
            'mountain'       => $mapped['mountain'],
            'difficulty'     => $mapped['difficulty'],
            'distance'       => [
                'official_km'   => $distanceOfficial,
                'calculated_km' => $distanceCalculated,
            ],
            'duration'       => [
                'official_minutes' => $mapped['duration']['minutes'],
                'official_text'    => $mapped['duration']['text'],
                'official_raw'     => $mapped['duration']['raw'],
            ],
            'elevation'      => [
                'official_gain_m'   => $gainOfficial,
                'official_loss_m'   => $lossOfficial,
                'calculated_gain_m' => $gainCalculated,
                'calculated_loss_m' => $lossCalculated,
                'min_m'             => $calculated['min_elevation_m'] ?? null,
                'max_m'             => $calculated['max_elevation_m'] ?? null,
            ],
            'start'          => $mapped['start'],
            'end'            => $mapped['end'],
            'description'    => $mapped['description'],
            'landmarks'      => $mapped['landmarks'],
            'maintenance'    => $mapped['maintenance'],
            'marking'        => $mapped['marking'],
            'accommodation'  => $mapped['accommodation'],
            'notes'          => $mapped['notes'],
            'map_reference'  => $mapped['map_reference'],
            'map_sheet'      => $mapped['map_sheet'],
            'club'           => $mapped['club'],
            'legend'         => $mapped['legend'],
            'photos_count'   => $mapped['photos_count'],
            'gpx'            => $gpxResult['gpx'],
            'geometry'       => $gpxResult['geometry'],
            'data_quality'   => $quality,
            'additional'     => $mapped['extra'],
            'source'         => [
                'name'             => (string) config('source.name'),
                'url'              => $url,
                'canonical_url'    => $canonical,
                'pss_id'           => $mapped['pss_id'],
                'updated_at'       => $sourceUpdatedAt,
                'published_at'     => $publishedAt,
                'last_checked_at'  => $now,
                'last_synced_at'   => $now,
            ],
            'search'         => $this->searchText($mapped, $gpxResult),
            'meta'           => [
                'official_values_from'   => 'PSS',
                'calculated_values_from' => 'GPX geometrija',
                'parser_version'         => self::PARSER_VERSION,
                'generated_at'           => $now,
            ],
        ];

        if (!$options->dryRun) {
            $this->repo->save($trail);
        }

        $isUnchanged = $response->notModified && ($gpxResult['reused'] || $gpxResult['status'] === 'missing');
        if ($existing === null) {
            $report->increment('created');
        } elseif ($isUnchanged) {
            $report->increment('unchanged');
        } else {
            $report->increment('updated');
        }
        $report->increment('processed');

        $gpxStatus = $gpxResult['status'];
        if ($gpxResult['available']) {
            $report->data['gpx']['available']++;
        }
        if ($gpxStatus === 'missing' || $gpxStatus === 'not_downloaded') {
            $report->data['gpx']['missing']++;
        } elseif ($gpxStatus === 'invalid') {
            $report->data['gpx']['invalid']++;
        } elseif ($gpxStatus === 'error') {
            $report->data['gpx']['errors']++;
        }
        if (($gpxResult['gpx']['has_elevation'] ?? false) === false && $gpxResult['available']) {
            $report->data['gpx']['no_elevation']++;
        }

        $this->log(sprintf(
            '  %s%s',
            $mapped['name'],
            $gpxResult['available'] ? ' · GPX' : ' · bez GPX-a'
        ));

        return ['slug' => $slug];
    }

    /**
     * Download, parse and cache the GPX for one trail. Returns the GPX metadata,
     * calculated stats and geometry, but does not write trail JSON.
     *
     * @return array{available:bool,status:string,stats:?array,gpx:array,geometry:array,reused:bool,error:?string}
     */
    private function handleGpx(
        string $slug,
        array $mapped,
        ?array $existing,
        ImportOptions $options,
        ImportReport $report,
        int &$gpxDownloads,
        bool $budgetExhausted,
        bool $pageNotModified
    ): array {
        $gpxUrl = $mapped['gpx_url'];
        $existingGpx = (array) ($existing['gpx'] ?? []);
        $existingGeometry = (array) ($existing['geometry'] ?? []);
        $existingGeometryOk = $existing !== null && $this->repo->hasGeojson($slug);
        $now = hajkic_now();

        $emptyGeometry = [
            'geojson'        => null,
            'bbox'           => null,
            'center'         => null,
            'start_point'    => null,
            'end_point'      => null,
            'is_loop'        => false,
            'overview_lines' => [],
        ];

        $baseGpx = [
            'available'       => false,
            'status'          => 'missing',
            'source_url'      => $gpxUrl,
            'fetched_at'      => null,
            'checked_at'      => $now,
            'bytes'           => null,
            'sha256'          => null,
            'etag'            => null,
            'last_modified'   => null,
            'parser_version'  => self::PARSER_VERSION,
            'has_elevation'   => false,
            'points'          => null,
            'tracks'          => null,
            'segments'        => null,
            'waypoints'       => null,
            'error'           => null,
            'download'        => $this->downloadInfo($slug, $gpxUrl),
        ];

        if ($gpxUrl === null) {
            $this->removeGeometry($slug, $options);
            return [
                'available' => false,
                'status'    => 'missing',
                'stats'     => null,
                'gpx'       => $baseGpx,
                'geometry'  => $emptyGeometry,
                'reused'    => false,
                'error'     => null,
            ];
        }

        $shouldSkipDownload = $options->skipGpx || $options->dryRun || $budgetExhausted;

        // Incremental run, unchanged page, unchanged GPX URL, valid local
        // geometry: no need to hit the GPX server at all.
        if (!$shouldSkipDownload
            && $options->incremental
            && !$options->force
            && $pageNotModified
            && $existingGeometryOk
            && (existingGpx['source_url'] ?? null) === $gpxUrl
            && ($existingGpx['sha256'] ?? null) !== null
            && in_array((string) ($existingGpx['status'] ?? ''), ['ok', 'stale'], true)
        ) {
            $gpxMeta = $existingGpx;
            $gpxMeta['source_url'] = $gpxUrl;
            $gpxMeta['available'] = true;
            $gpxMeta['checked_at'] = $now;
            $gpxMeta['download'] = $this->downloadInfo($slug, $gpxUrl);
            $report->data['gpx']['reused']++;

            return [
                'available' => true,
                'status'    => (string) $gpxMeta['status'],
                'stats'     => $this->statsFromExisting($existing),
                'gpx'       => $gpxMeta,
                'geometry'  => $existingGeometry,
                'reused'    => true,
                'error'     => null,
            ];
        }

        if ($shouldSkipDownload) {
            if ($existingGeometryOk && (existingGpx['source_url'] ?? null) === $gpxUrl) {
                $gpxMeta = $existingGpx;
                $gpxMeta['source_url'] = $gpxUrl;
                $gpxMeta['available'] = true;
                $gpxMeta['status'] = (string) ($existingGpx['status'] ?? 'ok');
                $gpxMeta['checked_at'] = $now;
                $gpxMeta['download'] = $this->downloadInfo($slug, $gpxUrl);

                return [
                    'available' => true,
                    'status'    => (string) $gpxMeta['status'],
                    'stats'     => $this->statsFromExisting($existing),
                    'gpx'       => $gpxMeta,
                    'geometry'  => $existingGeometry,
                    'reused'    => true,
                    'error'     => null,
                ];
            }

            return [
                'available' => false,
                'status'    => 'not_downloaded',
                'stats'     => null,
                'gpx'       => $baseGpx,
                'geometry'  => $emptyGeometry,
                'reused'    => false,
                'error'     => null,
            ];
        }

        $gpxTtl = $options->incremental ? 0 : (int) config('import.gpx_cache_ttl', 2592000);
        if ($options->force || $options->refresh) {
            $gpxTtl = 0;
        }

        $response = $this->http->get($gpxUrl, [
            'kind'      => 'gpx',
            'ttl'       => $gpxTtl,
            'force'     => $options->force || $options->refresh,
            'max_bytes' => (int) config('import.max_gpx_bytes', 25 * 1024 * 1024),
        ]);

        if (!$response->ok()) {
            $message = $response->error ?? ('HTTP ' . $response->status);
            $status = $response->status === 404 || $response->status === 410 ? 'missing' : 'error';
            $report->issue('error', 'gpx_download_error', $message, $slug, $gpxUrl);

            if ($existingGeometryOk && (existingGpx['source_url'] ?? null) === $gpxUrl) {
                $gpxMeta = $existingGpx;
                $gpxMeta['source_url'] = $gpxUrl;
                $gpxMeta['available'] = true;
                $gpxMeta['status'] = 'stale';
                $gpxMeta['checked_at'] = $now;
                $gpxMeta['error'] = $message;
                $gpxMeta['download'] = $this->downloadInfo($slug, $gpxUrl);

                return [
                    'available' => true,
                    'status'    => 'stale',
                    'stats'     => $this->statsFromExisting($existing),
                    'gpx'       => $gpxMeta,
                    'geometry'  => $existingGeometry,
                    'reused'    => true,
                    'error'     => $message,
                ];
            }

            return [
                'available' => false,
                'status'    => $status,
                'stats'     => null,
                'gpx'       => array_merge($baseGpx, ['status' => $status, 'error' => $message, 'checked_at' => $now]),
                'geometry'  => $emptyGeometry,
                'reused'    => false,
                'error'     => $message,
            ];
        }

        $body = $response->body;
        $sha = hash('sha256', $body);
        $bytes = strlen($body);

        // Keep a private copy for maintainers and offline reprocessing. It is
        // never served publicly unless redistribution is explicitly enabled.
        if (!$options->dryRun) {
            hajkic_ensure_dir(self::RAW_GPX_DIR);
            hajkic_write_atomic(self::RAW_GPX_DIR . '/' . $slug . '.gpx', $body);
        }

        $baseGpx['fetched_at'] = $now;
        $baseGpx['bytes'] = $bytes;
        $baseGpx['sha256'] = $sha;
        $baseGpx['etag'] = $response->header('etag');
        $baseGpx['last_modified'] = $response->header('last-modified');

        if (!$response->fromCache && !$response->notModified) {
            $gpxDownloads++;
            $report->data['gpx']['downloaded']++;
            $report->data['gpx']['downloaded_bytes'] += $bytes;
        } else {
            $report->data['gpx']['cached']++;
        }

        if ($existing !== null && $existingGeometryOk && !$options->force && (($existingGpx['sha256'] ?? null) === $sha)) {
            $report->data['gpx']['reused']++;

            return [
                'available' => true,
                'status'    => 'ok',
                'stats'     => $this->statsFromExisting($existing),
                'gpx'       => array_merge($baseGpx, $existingGpx, [
                    'available' => true,
                    'status'    => 'ok',
                    'fetched_at'=> $now,
                    'checked_at'=> $now,
                    'sha256'    => $sha,
                    'bytes'     => $bytes,
                    'etag'      => $baseGpx['etag'],
                    'last_modified' => $baseGpx['last_modified'],
                    'error'     => null,
                ]),
                'geometry'  => $existingGeometry,
                'reused'    => true,
                'error'     => null,
            ];
        }

        $parsedGpx = $this->gpx->parse($body);
        if (!$parsedGpx['ok']) {
            $this->removeGeometry($slug, $options);
            $error = $parsedGpx['error'] ?? 'GPX nije moguće parsirati.';
            $report->issue('error', 'invalid_gpx', $error, $slug, $gpxUrl);

            return [
                'available' => false,
                'status'    => 'invalid',
                'stats'     => null,
                'gpx'       => array_merge($baseGpx, [
                    'status'  => 'invalid',
                    'error'   => $error,
                    'points'  => $parsedGpx['counts']['points'] ?? 0,
                    'tracks'  => $parsedGpx['counts']['tracks'] ?? 0,
                    'segments'=> $parsedGpx['counts']['segments'] ?? 0,
                    'waypoints' => $parsedGpx['counts']['waypoints'] ?? 0,
                ]),
                'geometry'  => $emptyGeometry,
                'reused'    => false,
                'error'     => $error,
            ];
        }

        $processed = $this->routes->process(
            $parsedGpx,
            (float) config('import.simplify_overview_m', 130),
            (float) config('import.simplify_detail_m', 7),
            (int) config('import.max_detail_points', 4000)
        );

        if (!$processed['ok']) {
            $this->removeGeometry($slug, $options);
            $error = $processed['error'] ?? 'GPX nema upotrebljivu geometriju.';
            $report->issue('error', 'invalid_gpx', $error, $slug, $gpxUrl);

            return [
                'available' => false,
                'status'    => 'invalid',
                'stats'     => null,
                'gpx'       => array_merge($baseGpx, ['status' => 'invalid', 'error' => $error]),
                'geometry'  => $emptyGeometry,
                'reused'    => false,
                'error'     => $error,
            ];
        }

        if (!$options->dryRun) {
            $this->repo->writeGeojson($slug, $this->decorateGeojson($processed['detail'], $slug, $gpxUrl));
        }

        $stats = $processed['stats'];
        $geometry = [
            'geojson'        => 'data/geojson/' . $slug . '.geojson',
            'bbox'           => $processed['bbox'],
            'center'         => $processed['center'],
            'start_point'    => $processed['start'],
            'end_point'      => $processed['end'],
            'is_loop'        => (bool) ($stats['is_loop'] ?? false),
            'overview_lines' => $processed['overview']['geometry']['coordinates'] ?? [],
        ];

        return [
            'available' => true,
            'status'    => 'ok',
            'stats'     => $stats,
            'gpx'       => array_merge($baseGpx, [
                'available'      => true,
                'status'         => 'ok',
                'has_elevation'  => (bool) ($stats['has_elevation'] ?? false),
                'points'         => $stats['point_count'] ?? null,
                'tracks'         => $stats['track_count'] ?? null,
                'segments'       => $stats['segment_count'] ?? null,
                'waypoints'      => $stats['waypoint_count'] ?? null,
                'error'          => null,
            ]),
            'geometry'  => $geometry,
            'reused'    => false,
            'error'     => null,
        ];
    }

    /**
     * @return array{index:array<string,mixed>,overview:array<string,mixed>}
     */
    private function decorateGeojson(array $detail, string $slug, string $gpxUrl): array
    {
        $detail['properties'] = [
            'slug'       => $slug,
            'source_url' => $gpxUrl,
            'generated_at' => hajkic_now(),
            'notice'     => 'Geometrija je izvedena iz GPX-a i nije zvanična PSS dužina ni visina.',
        ];

        return $detail;
    }

    private function removeGeometry(string $slug, ImportOptions $options): void
    {
        if ($options->dryRun) {
            return;
        }
        $path = $this->repo->geojsonPath($slug);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function downloadInfo(string $slug, ?string $gpxUrl): array
    {
        if ($gpxUrl === null) {
            return ['mode' => 'none', 'url' => null];
        }

        if ((bool) config('import.redistribute_gpx', false)) {
            return [
                'mode' => 'local',
                'url'  => url('/download-gpx.php?slug=' . rawurlencode($slug)),
            ];
        }

        return [
            'mode' => 'source',
            'url'  => $gpxUrl,
            'note' => 'GPX se preuzima sa originalne PSS stranice; Hajkić ne redistribuira datoteku.',
        ];
    }

    /**
     * Reconstruct calculated statistics from a stored trail document.
     *
     * @return array<string,mixed>|null
     */
    private function statsFromExisting(array $existing): ?array
    {
        if (($existing['distance']['calculated_km'] ?? null) === null
            && ($existing['elevation']['calculated_gain_m'] ?? null) === null) {
            return null;
        }

        $elevation = (array) ($existing['elevation'] ?? []);
        $gpx = (array) ($existing['gpx'] ?? []);

        return [
            'distance_km'     => $existing['distance']['calculated_km'] ?? null,
            'ascent_m'        => $elevation['calculated_gain_m'] ?? null,
            'descent_m'       => $elevation['calculated_loss_m'] ?? null,
            'min_elevation_m' => $elevation['min_m'] ?? null,
            'max_elevation_m' => $elevation['max_m'] ?? null,
            'point_count'     => $gpx['points'] ?? null,
            'track_count'     => $gpx['tracks'] ?? null,
            'segment_count'   => $gpx['segments'] ?? null,
            'waypoint_count'  => $gpx['waypoints'] ?? null,
            'has_elevation'   => (bool) ($gpx['has_elevation'] ?? false),
            'is_loop'         => (bool) ($existing['geometry']['is_loop'] ?? false),
        ];
    }

    /**
     * @return array{level:string,issues:list<string>}
     */
    private function dataQuality(array $mapped, array $gpx, ?float $distanceOfficial, ?float $distanceCalculated): array
    {
        $issues = [];
        if ($mapped['code'] === null) {
            $issues[] = 'missing_code';
        }
        if ($mapped['region_name'] === null) {
            $issues[] = 'missing_region';
        }
        if ($mapped['mountain'] === null) {
            $issues[] = 'missing_mountain';
        }
        if ($mapped['difficulty']['key'] === 'nepoznato') {
            $issues[] = 'missing_difficulty';
        }
        if ($distanceOfficial === null && $distanceCalculated === null) {
            $issues[] = 'missing_distance';
        }
        if ($mapped['description'] === null) {
            $issues[] = 'missing_description';
        }
        if (!$gpx['available']) {
            $issues[] = 'missing_gpx';
        }
        if ($gpx['available'] && !($gpx['gpx']['has_elevation'] ?? false)) {
            $issues[] = 'missing_elevation';
        }

        $hasGpx = $gpx['available'];
        $hasDescription = $mapped['description'] !== null;
        $hasCore = $mapped['code'] !== null
            && ($distanceOfficial !== null || $distanceCalculated !== null)
            && $mapped['mountain'] !== null
            && $mapped['difficulty']['key'] !== 'nepoznato';

        if ($hasGpx && $hasDescription && $hasCore) {
            $level = 'complete';
        } elseif ($mapped['name'] !== '' && ($hasGpx || $hasDescription || $hasCore)) {
            $level = 'partial';
        } else {
            $level = 'minimal';
        }

        return ['level' => $level, 'issues' => array_values(array_unique($issues))];
    }

    private function qualityMessage(string $code): string
    {
        return match ($code) {
            'missing_code'        => 'Nedostaje PSS oznaka staze.',
            'missing_region'      => 'Nedostaje region.',
            'missing_mountain'    => 'Nedostaje planina/predeo.',
            'missing_difficulty'  => 'Nedostaje težina staze.',
            'missing_distance'    => 'Nedostaje dužina staze.',
            'missing_description' => 'Nedostaje opis staze.',
            'missing_gpx'         => 'Nema GPX datoteke na PSS stranici.',
            'invalid_gpx'         => 'GPX datoteka nije upotrebljiva.',
            'gpx_download_error'  => 'GPX nije moguće preuzeti.',
            'missing_elevation'   => 'GPX nema upotrebljive visinske podatke.',
            default               => $code,
        };
    }

    private function searchText(array $mapped, array $gpx): string
    {
        $parts = [
            $mapped['name'],
            $mapped['code'],
            $mapped['region_name'],
            $mapped['mountain'],
            $mapped['start'],
            $mapped['end'],
            $mapped['club']['name'] ?? null,
            $mapped['landmarks'],
            $mapped['difficulty']['label'] ?? null,
            mb_substr((string) ($mapped['description'] ?? ''), 0, 1800, 'UTF-8'),
        ];

        $text = implode(' ', array_filter($parts, static fn ($value) => is_string($value) && $value !== ''));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_substr(hajkic_fold($text), 0, 4000, 'UTF-8');
    }

    /**
     * Discover candidate trail pages from the register (with pagination) and
     * optionally supplement with sitemap-only URLs.
     *
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function discover(ImportOptions $options, ImportReport $report): array
    {
        $context = ['regions' => [], 'terrains' => [], 'types' => []];
        $candidates = [];
        $byId = [];
        $byUrl = [];

        if ($options->slug !== null) {
            $url = rtrim((string) config('source.trail_base'), '/') . '/' . $options->slug . '/';
            $candidates[] = [
                'id'       => null,
                'title'    => null,
                'url'      => $url,
                'slug'     => $options->slug,
                'lastmod'  => null,
                'source'   => 'direct',
            ];

            // Best-effort context for region/mountain names.
            try {
                $register = $this->fetchRegisterPage(1, $options, $report, (string) config('source.category_slug', 'planinarski-putevi'));
                if ($register !== null) {
                    $context = ['regions' => $register['regions'], 'terrains' => $register['terrains'], 'types' => $register['types']];
                }
            } catch (Throwable) {
                // Not fatal for a single-trail refresh.
            }

            return [$candidates, $context, [
                'archive_posts' => 0,
                'sitemap_urls'  => 0,
                'sitemap_only'  => 0,
                'unique_candidates' => 1,
                'not_trails'    => 0,
            ]];
        }

        $maxPages = $options->maxPages > 0 ? $options->maxPages : (int) config('import.max_pages', 60);
        $categories = (array) config('source.categories', []);
        if ($options->category !== 'all') {
            if (!isset($categories[$options->category])) {
                throw new \RuntimeException('Nepoznata kategorija: ' . $options->category);
            }
            $categories = [$options->category => $categories[$options->category]];
        }
        if ($categories === []) {
            throw new \RuntimeException('Nijedna kategorija nije konfigurisana.');
        }

        $candidates = [];
        $byId = [];
        $byUrl = [];
        $context = ['regions' => [], 'terrains' => [], 'types' => []];
        $archivePosts = 0;
        $pagesTotal = 0;

        foreach ($categories as $categorySlug => $categoryConfig) {
            $this->log('Kategorija: ' . ($categoryConfig['label'] ?? $categorySlug));
            $discovery = new \Hajkic\Pss\PssDiscovery(
                function (int $page) use ($options, $report, $categorySlug): ?string {
                    return $this->fetchRegisterHtml($page, $options, $report, $categorySlug);
                },
                $this->pss,
                function (string $message): void {
                    $this->log($message);
                }
            );
            $collected = $discovery->collectArchive($categorySlug, $maxPages);
            $pagesTotal += (int) $collected['pages_fetched'];
            if ($context['regions'] === [] && $collected['context']['regions'] !== []) {
                $context = $collected['context'];
            }
            foreach ($collected['candidates'] as $candidate) {
                $key = $candidate['id'] !== null ? 'id:' . $candidate['id'] : 'url:' . $candidate['url'];
                if (isset($byId[$key]) || isset($byUrl[$candidate['url']])) {
                    continue;
                }
                $byId[$key] = true;
                $byUrl[$candidate['url']] = true;
                $candidate['category'] = $categorySlug;
                $candidate['category_label'] = $categoryConfig['label'] ?? $categorySlug;
                $candidates[] = $candidate;
                $archivePosts++;
            }
            $this->log(sprintf('  %d kandidata u kategoriji.', count($collected['candidates'])));
        }

        usort($candidates, static function (array $a, array $b): int {
            $idA = $a['id'] ?? PHP_INT_MAX;
            $idB = $b['id'] ?? PHP_INT_MAX;
            return $idA === $idB ? strcmp((string) $a['slug'], (string) $b['slug']) : $idA <=> $idB;
        });

        $report->data['discovered']['archive_posts'] = $archivePosts;
        $report->data['discovered']['pages_fetched'] = $pagesTotal;


        $sitemapUrls = 0;
        $sitemapOnly = 0;
        if ($options->includeSitemap) {
            try {
                $sitemapResponse = $this->http->get((string) config('source.sitemap'), [
                    'kind'  => 'page',
                    'ttl'   => $options->incremental ? 0 : (int) config('import.page_cache_ttl', 604800),
                    'force' => $options->force || $options->refresh,
                ]);
                if ($sitemapResponse->ok()) {
                    $entries = $this->pss->parseSitemap($sitemapResponse->body);
                    $sitemapUrls = count($entries);
                    $lastmods = [];
                    foreach ($entries as $entry) {
                        $normalized = hajkic_normalize_url($entry['url']);
                        if ($normalized === null) {
                            continue;
                        }
                        $lastmods[$normalized] = $entry['lastmod'];
                    }
                    foreach ($candidates as &$candidate) {
                        if (isset($lastmods[$candidate['url']])) {
                            $candidate['lastmod'] = $lastmods[$candidate['url']];
                        }
                    }
                    unset($candidate);

                    foreach ($entries as $entry) {
                        $url = hajkic_normalize_url($entry['url']);
                        if ($url === null || isset($byUrl[$url])) {
                            continue;
                        }
                        $slug = hajkic_slug_from_url($url);
                        if ($slug === null) {
                            continue;
                        }
                        $byUrl[$url] = true;
                        $candidates[] = [
                            'id'      => null,
                            'title'   => null,
                            'url'     => $url,
                            'slug'    => $slug,
                            'lastmod' => $entry['lastmod'],
                            'source'  => 'sitemap',
                        ];
                        $sitemapOnly++;
                    }
                    $this->log(sprintf('Sitemap: %d URL-ova, %d dodatnih kandidata.', $sitemapUrls, $sitemapOnly));
                } else {
                    $report->issue('warning', 'sitemap_fetch_failed', $sitemapResponse->error ?? ('HTTP ' . $sitemapResponse->status), null, (string) config('source.sitemap'));
                }
            } catch (Throwable $e) {
                $report->issue('warning', 'sitemap_fetch_failed', $e->getMessage(), null, (string) config('source.sitemap'));
            }
        }

        $discovery = [
            'archive_posts'     => $report->data['discovered']['archive_posts'],
            'sitemap_urls'      => $sitemapUrls,
            'sitemap_only'      => $sitemapOnly,
            'unique_candidates' => count($candidates),
            'not_trails'        => 0,
        ];

        return [$candidates, $context, $discovery];
    }

    private function fetchRegisterHtml(int $page, ImportOptions $options, ImportReport $report, ?string $category = null): ?string
    {
        $category = $category ?? ($options->category !== 'all' ? $options->category : (string) config('source.category_slug', 'planinarski-putevi'));
        $url = \Hajkic\Pss\PssDiscovery::archiveUrl((string) config('source.archive'), $page, $category);
        $ttl = $options->incremental ? 0 : (int) config('import.page_cache_ttl', 604800);
        if ($options->force || $options->refresh) {
            $ttl = 0;
        }

        $response = $this->http->get($url, [
            'kind'  => 'page',
            'ttl'   => $ttl,
            'force' => $options->force || $options->refresh,
        ]);

        if (!$response->ok()) {
            if ($page === 1) {
                throw new \RuntimeException('Registar nije dostupan: ' . ($response->error ?? 'HTTP ' . $response->status));
            }
            $report->issue('warning', 'register_page_failed', $response->error ?? ('HTTP ' . $response->status), null, $url);
            return null;
        }

        return $response->body;
    }

    /**
     * @return array{posts:list<array<string,mixed>>,terrains:list<array<string,mixed>>,regions:list<array<string,mixed>>,types:array<string,mixed>}|null
     */
    private function fetchRegisterPage(int $page, ImportOptions $options, ImportReport $report, ?string $category = null): ?array
    {
        $html = $this->fetchRegisterHtml($page, $options, $report, $category);
        if ($html === null) {
            return null;
        }

        $url = \Hajkic\Pss\PssDiscovery::archiveUrl((string) config('source.archive'), $page, $category ?? (string) config('source.category_slug', 'planinarski-putevi'));
        try {
            $parsed = $this->pss->parseRegister($html);
        } catch (Throwable $e) {
            if ($page === 1) {
                throw $e;
            }
            $report->issue('warning', 'register_parse_failed', $e->getMessage(), null, $url);
            return null;
        }

        return [
            'posts'    => $parsed['posts'],
            'terrains' => $parsed['terrains'],
            'regions'  => $parsed['regions'],
            'types'    => $parsed['types'],
        ];
    }

    /**
     * @param array<string, true> $keepSlugs
     */
    private function prune(array $keepSlugs): int
    {
        $removed = 0;
        foreach ($this->repo->slugs() as $slug) {
            if (isset($keepSlugs[$slug])) {
                continue;
            }
            $this->repo->delete($slug);
            $removed++;
        }
        return $removed;
    }

    private function printSummary(array $report): void
    {
        $this->log('---');
        $this->log(sprintf(
            'Gotovo za %ss: %d obrađeno, %d novo, %d izmenjeno, %d nepromenjeno, %d preskočeno, %d grešaka.',
            $report['duration_s'],
            $report['processed'],
            $report['created'],
            $report['updated'],
            $report['unchanged'],
            $report['skipped'],
            $report['failed']
        ));
        $this->log(sprintf(
            'GPX: %d dostupno, %d preuzeto, %d iz keša, %d ponovo iskorišćeno, %d bez GPX-a, %d neispravno.',
            $report['gpx']['available'],
            $report['gpx']['downloaded'],
            $report['gpx']['cached'],
            $report['gpx']['reused'],
            $report['gpx']['missing'],
            $report['gpx']['invalid']
        ));

        if (isset($report['catalogue']['trails'])) {
            $this->log(sprintf(
                'Dataset: %d staza, %d sa GPX-om, %d sa visinskim profilom.',
                $report['catalogue']['trails'],
                $report['catalogue']['with_gpx'],
                $report['catalogue']['with_elevation']
            ));
        }

        $issues = $report['issues'] ?? [];
        if ($issues !== []) {
            $this->log('Napomene (prvih 12):');
            foreach (array_slice($issues, 0, 12) as $issue) {
                $this->log(sprintf('  [%s] %s: %s', $issue['level'], $issue['code'], $issue['message']));
            }
            if (count($issues) > 12) {
                $this->log(sprintf('  … još %d u import-report.json', count($issues) - 12));
            }
        }
    }

    private function log(string $message): void
    {
        if (PHP_SAPI === 'cli') {
            ($this->logger)($message);
        }

        $line = gmdate('c') . ' ' . $message . PHP_EOL;
        @file_put_contents(HAJKIC_ROOT . '/storage/logs/import.log', $line, FILE_APPEND | LOCK_EX);
    }
}
