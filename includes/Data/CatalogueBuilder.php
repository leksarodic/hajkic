<?php

declare(strict_types=1);

namespace Hajkic\Data;

/**
 * Rebuilds the lightweight index, facet counts and overview geometry from the
 * per-trail JSON files. The index is the only file the browser needs at start.
 */
final class CatalogueBuilder
{
    public const SCHEMA_VERSION = 1;

    private TrailRepository $repo;

    public function __construct(?TrailRepository $repo = null)
    {
        $this->repo = $repo ?? new TrailRepository();
    }

    /**
     * @return array{index:array<string,mixed>,overview:array<string,mixed>,counts:array<string,int>}
     */
    public function build(bool $write = true): array
    {
        $trails = $this->repo->all();
        $this->sortTrails($trails);

        $indexTrails = [];
        $overviewFeatures = [];
        $counts = [
            'trails'          => 0,
            'with_gpx'        => 0,
            'with_elevation'  => 0,
            'missing_gpx'     => 0,
            'invalid_gpx'     => 0,
            'complete'        => 0,
            'partial'         => 0,
            'minimal'         => 0,
        ];

        $regionCounts = [];
        $mountainCounts = [];
        $categoryCounts = [];
        $difficultyCounts = [
            'laka' => 0,
            'srednje_teska' => 0,
            'zahtevna' => 0,
            'vrlo_teska' => 0,
            'ostalo' => 0,
            'nepoznato' => 0,
        ];
        $distanceBuckets = ['0-5' => 0, '5-10' => 0, '10-20' => 0, '20-30' => 0, '30+' => 0];
        $elevationBuckets = ['0-300' => 0, '300-700' => 0, '700-1200' => 0, '1200+' => 0];

        foreach ($trails as $trail) {
            $slug = (string) ($trail['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $counts['trails']++;
            $gpx = (array) ($trail['gpx'] ?? []);
            $gpxStatus = (string) ($gpx['status'] ?? 'missing');
            $hasGeometry = (bool) ($gpx['available'] ?? false) && $this->repo->hasGeojson($slug);
            $hasElevation = $hasGeometry && (bool) ($gpx['has_elevation'] ?? false);

            if ($hasGeometry) {
                $counts['with_gpx']++;
            } elseif ($gpxStatus === 'invalid') {
                $counts['invalid_gpx']++;
            } else {
                $counts['missing_gpx']++;
            }
            if ($hasElevation) {
                $counts['with_elevation']++;
            }

            $quality = (string) ($trail['data_quality']['level'] ?? 'minimal');
            $counts[$quality] = ($counts[$quality] ?? 0) + 1;

            $name = (string) ($trail['name'] ?? $slug);
            $region = $trail['region'] ?? null;
            $mountain = $trail['mountain'] ?? null;
            $difficulty = (array) ($trail['difficulty'] ?? ['key' => 'nepoznato', 'label' => 'Nije navedeno']);
            $distance = (array) ($trail['distance'] ?? []);
            $duration = (array) ($trail['duration'] ?? []);
            $elevation = (array) ($trail['elevation'] ?? []);
            $club = (array) ($trail['club'] ?? []);

            $officialDistance = isset($distance['official_km']) ? (float) $distance['official_km'] : null;
            $calculatedDistance = isset($distance['calculated_km']) ? (float) $distance['calculated_km'] : null;
            $effectiveDistance = $officialDistance ?? $calculatedDistance;
            $officialGain = isset($elevation['official_gain_m']) ? (float) $elevation['official_gain_m'] : null;
            $calculatedGain = isset($elevation['calculated_gain_m']) ? (float) $elevation['calculated_gain_m'] : null;
            $effectiveGain = $officialGain ?? $calculatedGain;

            $categoryKey = (string) ($trail['category']['key'] ?? 'planinarski-putevi');
            $categoryLabel = (string) ($trail['category']['label'] ?? 'Planinarski i pešački putevi');
            $categoryCounts[$categoryKey] = [
                'label' => $categoryLabel,
                'count' => (int) (($categoryCounts[$categoryKey]['count'] ?? 0) + 1),
            ];

            if ($region !== null) {
                $regionCounts[$region] = ($regionCounts[$region] ?? 0) + 1;
            }
            if ($mountain !== null) {
                $mountainCounts[$mountain] = ($mountainCounts[$mountain] ?? 0) + 1;
            }
            $difficultyKey = (string) ($difficulty['key'] ?? 'nepoznato');
            $difficultyCounts[$difficultyKey] = ($difficultyCounts[$difficultyKey] ?? 0) + 1;

            foreach ($this->distanceBuckets() as $key => [$min, $max]) {
                if ($effectiveDistance !== null && $effectiveDistance >= $min && ($max === null || $effectiveDistance < $max)) {
                    $distanceBuckets[$key]++;
                    break;
                }
            }
            foreach ($this->elevationBuckets() as $key => [$min, $max]) {
                if ($effectiveGain !== null && $effectiveGain >= $min && ($max === null || $effectiveGain < $max)) {
                    $elevationBuckets[$key]++;
                    break;
                }
            }

            $indexTrails[] = [
                'slug'             => $slug,
                'name'             => $name,
                'code'             => $trail['code'] ?? null,
                'category'         => $trail['category']['key'] ?? 'planinarski-putevi',
                'category_label'   => $trail['category']['label'] ?? 'Planinarski i pešački putevi',
                'region'           => $region,
                'mountain'         => $mountain,
                'difficulty'       => $difficultyKey,
                'difficulty_label' => $difficulty['label'] ?? 'Nije navedeno',
                'distance'         => [
                    'official_km'    => $officialDistance,
                    'calculated_km'  => $calculatedDistance,
                ],
                'duration'         => [
                    'minutes' => $duration['official_minutes'] ?? null,
                    'text'    => $duration['official_text'] ?? null,
                ],
                'elevation'        => [
                    'official_gain_m'    => $officialGain,
                    'official_loss_m'    => isset($elevation['official_loss_m']) ? (float) $elevation['official_loss_m'] : null,
                    'calculated_gain_m'  => $calculatedGain,
                    'calculated_loss_m'  => isset($elevation['calculated_loss_m']) ? (float) $elevation['calculated_loss_m'] : null,
                    'min_m'              => isset($elevation['min_m']) ? (float) $elevation['min_m'] : null,
                    'max_m'              => isset($elevation['max_m']) ? (float) $elevation['max_m'] : null,
                ],
                'gpx'              => [
                    'available'      => $hasGeometry,
                    'status'         => $gpxStatus,
                    'has_elevation'  => $hasElevation,
                ],
                'start'            => $trail['start'] ?? null,
                'end'              => $trail['end'] ?? null,
                'club'             => $club['name'] ?? null,
                'center'           => $trail['geometry']['center'] ?? null,
                'start_point'      => $trail['geometry']['start_point'] ?? null,
                'end_point'        => $trail['geometry']['end_point'] ?? null,
                'bbox'             => $trail['geometry']['bbox'] ?? null,
                'source'           => [
                    'url'          => $trail['source']['url'] ?? null,
                    'updated_at'   => $trail['source']['updated_at'] ?? null,
                    'checked_at'   => $trail['source']['last_checked_at'] ?? null,
                ],
                'quality'          => $quality,
                'search'           => (string) ($trail['search'] ?? ''),
            ];

            if (($trail['geometry']['overview_lines'] ?? []) !== []) {
                $overviewFeatures[] = [
                    'type'       => 'Feature',
                    'geometry'   => [
                        'type'        => 'MultiLineString',
                        'coordinates' => $trail['geometry']['overview_lines'],
                    ],
                    'properties' => [
                        'slug'       => $slug,
                        'name'       => $name,
                        'difficulty' => $difficultyKey,
                        'distance'   => $effectiveDistance,
                    ],
                ];
            }
        }

        $facets = [
            'regions'     => $this->facetList($regionCounts),
            'mountains'   => $this->facetList($mountainCounts),
            'difficulties'=> $this->difficultyFacets($difficultyCounts),
            'distance'    => $this->bucketFacets($distanceBuckets),
            'elevation'   => $this->bucketFacets($elevationBuckets),
            'gpx'         => [
                ['key' => 'yes', 'label' => 'Sa GPX-om', 'count' => $counts['with_gpx']],
                ['key' => 'no', 'label' => 'Bez GPX-a', 'count' => $counts['trails'] - $counts['with_gpx']],
            ],
            'categories'  => array_values(array_map(
                static fn (string $key, array $item): array => ['key' => $key, 'label' => $item['label'], 'count' => $item['count']],
                array_keys($categoryCounts),
                array_values($categoryCounts)
            )),
        ];

        $generatedAt = hajkic_now();
        $overview = [
            'type'     => 'FeatureCollection',
            'features' => $overviewFeatures,
        ];

        $lastImport = hajkic_read_json($this->repo->dataDir() . '/import-report.json');
        $index = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => $generatedAt,
            'source'         => [
                'name'       => config('source.name'),
                'url'        => config('source.register'),
                'disclaimer' => config('source.disclaimer'),
            ],
            'counts'         => $counts,
            'facets'         => $facets,
            'last_import'    => $lastImport !== null ? [
                'run_at'    => $lastImport['run_at'] ?? null,
                'mode'      => $lastImport['mode'] ?? null,
                'duration_s'=> $lastImport['duration_s'] ?? null,
            ] : null,
            'trails'         => $indexTrails,
        ];

        if ($write) {
            hajkic_write_atomic($this->repo->indexPath(), hajkic_json($index, true));
            $overviewJson = json_encode(
                hajkic_clean_utf8($overview),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
            hajkic_write_atomic($this->repo->overviewPath(), $overviewJson . "\n");

            $placesPath = $this->repo->dataDir() . '/places.geojson';
            if (!is_file($placesPath)) {
                hajkic_write_atomic($placesPath, json_encode([
                    'type' => 'FeatureCollection',
                    'features' => [],
                    'metadata' => [
                        'note' => 'Rezervisano za buduće slojeve: vrhovi, vidikovci, domovi, skloništa, izvori, jezera i slično. Podaci se ne izmišljaju.',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            }

            hajkic_write_atomic($this->repo->dataDir() . '/dataset.json', hajkic_json([
                'schema_version' => self::SCHEMA_VERSION,
                'generated_at'   => $generatedAt,
                'counts'         => $counts,
                'source'         => [
                    'name' => config('source.name'),
                    'url'  => config('source.register'),
                ],
            ], true));
        }

        return ['index' => $index, 'overview' => $overview, 'counts' => $counts];
    }

    /**
     * @param list<array<string,mixed>> $trails
     */
    private function sortTrails(array &$trails): void
    {
        $collator = class_exists(\Collator::class) ? new \Collator('sr_RS') : null;

        usort($trails, static function (array $a, array $b) use ($collator): int {
            $nameA = (string) ($a['name'] ?? '');
            $nameB = (string) ($b['name'] ?? '');
            $result = $collator !== null
                ? $collator->compare($nameA, $nameB)
                : strnatcasecmp(hajkic_fold($nameA), hajkic_fold($nameB));

            return $result !== 0 ? $result : strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? ''));
        });
    }

    /**
     * @param array<string,int> $counts
     * @return list<array{key:string,label:string,count:int}>
     */
    private function facetList(array $counts): array
    {
        $items = [];
        foreach ($counts as $key => $count) {
            if ($count <= 0) {
                continue;
            }
            $items[] = ['key' => (string) $key, 'label' => (string) $key, 'count' => $count];
        }

        $collator = class_exists(\Collator::class) ? new \Collator('sr_RS') : null;
        usort($items, static function (array $a, array $b) use ($collator): int {
            if ($collator !== null) {
                return $collator->compare($a['label'], $b['label']);
            }
            return strnatcasecmp(hajkic_fold($a['label']), hajkic_fold($b['label']));
        });

        return $items;
    }

    /**
     * @param array<string,int> $counts
     * @return list<array{key:string,label:string,count:int}>
     */
    private function difficultyFacets(array $counts): array
    {
        $labels = [
            'laka'          => 'Laka',
            'srednje_teska' => 'Srednje teška',
            'zahtevna'      => 'Zahtevna',
            'vrlo_teska'    => 'Vrlo teška',
            'ostalo'        => 'Ostalo',
            'nepoznato'     => 'Nije navedeno',
        ];

        $items = [];
        foreach ($labels as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $items;
    }

    /**
     * @param array<string,int> $counts
     * @return list<array{key:string,label:string,count:int}>
     */
    private function bucketFacets(array $counts): array
    {
        $labels = [
            '0-5'       => 'do 5 km',
            '5-10'      => '5–10 km',
            '10-20'     => '10–20 km',
            '20-30'     => '20–30 km',
            '30+'       => 'preko 30 km',
            '0-300'     => 'do 300 m',
            '300-700'   => '300–700 m',
            '700-1200'  => '700–1.200 m',
            '1200+'     => 'preko 1.200 m',
        ];

        $items = [];
        foreach ($counts as $key => $count) {
            $items[] = ['key' => (string) $key, 'label' => $labels[$key] ?? (string) $key, 'count' => (int) $count];
        }

        return $items;
    }

    /**
     * @return array<string, array{0:float,1:?float}>
     */
    private function distanceBuckets(): array
    {
        return ['0-5' => [0.0, 5.0], '5-10' => [5.0, 10.0], '10-20' => [10.0, 20.0], '20-30' => [20.0, 30.0], '30+' => [30.0, null]];
    }

    /**
     * @return array<string, array{0:float,1:?float}>
     */
    private function elevationBuckets(): array
    {
        return ['0-300' => [0.0, 300.0], '300-700' => [300.0, 700.0], '700-1200' => [700.0, 1200.0], '1200+' => [1200.0, null]];
    }
}
