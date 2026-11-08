<?php

declare(strict_types=1);

namespace Hajkic\Geo;

/**
 * Turns parsed GPX into calculated statistics and GeoJSON documents.
 *
 * Official PSS values live elsewhere and are never derived here.
 */
final class RouteProcessor
{
    private Elevation $elevation;

    public function __construct()
    {
        $this->elevation = new Elevation();
    }

    /**
     * @param array<string, mixed> $parsed result of GpxParser::parse()
     * @return array<string, mixed>
     */
    public function process(
        array $parsed,
        float $overviewToleranceMeters = 130.0,
        float $detailToleranceMeters = 7.0,
        int $maxDetailPoints = 4000,
    ): array {
        $segments = GpxParser::flatSegments($parsed);
        if ($segments === []) {
            return [
                'ok'       => false,
                'error'    => 'Nema segmenta sa najmanje dve tačke.',
                'stats'    => null,
                'detail'   => null,
                'overview' => null,
                'start'    => null,
                'end'      => null,
                'bbox'     => null,
                'center'   => null,
            ];
        }

        $distanceMeters = 0.0;
        $allElevations = [];
        $allCoordinates = [];

        foreach ($segments as $segmentIndex => $segment) {
            $previous = null;
            foreach ($segment['points'] as $point) {
                if ($previous !== null) {
                    $distanceMeters += Geo::haversineMeters(
                        (float) $previous['lat'],
                        (float) $previous['lon'],
                        (float) $point['lat'],
                        (float) $point['lon']
                    );
                }
                $previous = $point;
                $allElevations[] = $point['ele'];
                $allCoordinates[] = [(float) $point['lon'], (float) $point['lat']];
            }
            // Break elevation runs so a jump between segments is not ascent.
            $allElevations[] = null;
        }

        $elevationStats = $this->elevation->analyse($allElevations);
        $bbox = Geo::bbox($allCoordinates);

        $first = $segments[0]['points'][0];
        $lastSegment = $segments[count($segments) - 1];
        $last = $lastSegment['points'][count($lastSegment['points']) - 1];
        $start = [(float) $first['lon'], (float) $first['lat']];
        $end = [(float) $last['lon'], (float) $last['lat']];
        $loop = Geo::haversineMeters($start[1], $start[0], $end[1], $end[0]) < 40.0;

        $detailFeatures = [];
        $overviewLines = [];
        $totalDetailPoints = 0;

        foreach ($segments as $segment) {
            $rawCoordinates = [];
            $rawElevations = [];
            $rawDetail = [];
            foreach ($segment['points'] as $point) {
                $coordinate = [(float) $point['lon'], (float) $point['lat']];
                $rawCoordinates[] = $coordinate;
                $rawElevations[] = $point['ele'];
                $rawDetail[] = $point['ele'] === null ? $coordinate : [$coordinate[0], $coordinate[1], (float) $point['ele']];
            }

            // 3D simplification preserves climbs and descents on otherwise
            // straight stretches, so the elevation profile keeps its shape.
            $simplified = Simplifier::simplify($rawDetail, $detailToleranceMeters, $maxDetailPoints);
            $keptElevations = $this->elevationsForKeptPoints($rawCoordinates, $rawElevations, $simplified);
            $totalDetailPoints += count($simplified);

            $coordinates = [];
            $elevations = [];
            foreach ($simplified as $index => $coordinate) {
                $coordinates[] = [round($coordinate[0], 6), round($coordinate[1], 6)];
                $ele = $keptElevations[$index] ?? null;
                $elevations[] = $ele === null ? null : round($ele, 1);
            }

            $detailFeatures[] = [
                'type'       => 'Feature',
                'geometry'   => ['type' => 'LineString', 'coordinates' => $coordinates],
                'properties' => [
                    'kind'          => 'trail',
                    'track'         => $segment['track'],
                    'segment'       => $segment['segment'],
                    'name'          => $segment['name'],
                    'distance_m'    => round(Geo::pathLengthMeters($coordinates), 1),
                    'has_elevation' => $parsed['has_elevation'] ?? false,
                    'elevations_m'  => $elevations,
                ],
            ];

            $overview = Simplifier::simplify($rawCoordinates, $overviewToleranceMeters, 260);
            $overviewCoordinates = [];
            foreach ($overview as $coordinate) {
                $overviewCoordinates[] = [round($coordinate[0], 5), round($coordinate[1], 5)];
            }
            if (count($overviewCoordinates) >= 2) {
                $overviewLines[] = $overviewCoordinates;
            }
        }

        foreach ((array) ($parsed['waypoints'] ?? []) as $waypoint) {
            $detailFeatures[] = [
                'type'       => 'Feature',
                'geometry'   => [
                    'type'        => 'Point',
                    'coordinates' => [round((float) $waypoint['lon'], 6), round((float) $waypoint['lat'], 6)],
                ],
                'properties' => [
                    'kind'        => 'waypoint',
                    'name'        => $waypoint['name'] ?? null,
                    'description' => $waypoint['desc'] ?? $waypoint['cmt'] ?? null,
                    'symbol'      => $waypoint['sym'] ?? null,
                    'elevation_m' => $waypoint['ele'] === null ? null : round((float) $waypoint['ele'], 1),
                ],
            ];
        }

        $detail = [
            'type'     => 'FeatureCollection',
            'features' => $detailFeatures,
        ];

        $overview = null;
        if ($overviewLines !== []) {
            $overview = [
                'type'       => 'Feature',
                'geometry'   => ['type' => 'MultiLineString', 'coordinates' => $overviewLines],
                'properties' => [
                    'kind' => 'trail',
                ],
            ];
        }

        $stats = [
            'distance_km'       => round($distanceMeters / 1000, 2),
            'ascent_m'          => $elevationStats['gain_m'],
            'descent_m'         => $elevationStats['loss_m'],
            'min_elevation_m'   => $elevationStats['min_m'],
            'max_elevation_m'   => $elevationStats['max_m'],
            'elevation_points'  => $elevationStats['valid'],
            'point_count'       => (int) ($parsed['counts']['points'] ?? 0),
            'track_count'       => (int) ($parsed['counts']['tracks'] ?? 0),
            'segment_count'     => (int) ($parsed['counts']['segments'] ?? 0),
            'waypoint_count'    => (int) ($parsed['counts']['waypoints'] ?? 0),
            'detail_points'     => $totalDetailPoints,
            'has_elevation'     => (bool) ($parsed['has_elevation'] ?? false),
            'is_loop'           => $loop,
        ];

        return [
            'ok'       => true,
            'error'    => null,
            'stats'    => $stats,
            'detail'   => $detail,
            'overview' => $overview,
            'start'    => $start,
            'end'      => $end,
            'bbox'     => $bbox,
            'center'   => $bbox !== null ? Geo::center($bbox) : null,
        ];
    }

    /**
     * Match simplified coordinates back to their elevation values.
     *
     * Simplifier preserves references to the original positions, so identity
     * matching by lon/lat is safe here (coordinates came from the same array).
     *
     * @param list<array{0:float,1:float}> $rawCoordinates
     * @param list<float|null> $rawElevations
     * @param list<array{0:float,1:float}> $simplified
     * @return list<float|null>
     */
    private function elevationsForKeptPoints(array $rawCoordinates, array $rawElevations, array $simplified): array
    {
        $lookup = [];
        foreach ($rawCoordinates as $index => $coordinate) {
            $lookup[$this->coordinateKey($coordinate)] = $rawElevations[$index] ?? null;
        }

        $result = [];
        foreach ($simplified as $coordinate) {
            $result[] = $lookup[$this->coordinateKey($coordinate)] ?? null;
        }

        return $result;
    }

    private function coordinateKey(array $coordinate): string
    {
        return sprintf('%.9F,%.9F', $coordinate[0], $coordinate[1]);
    }
}
