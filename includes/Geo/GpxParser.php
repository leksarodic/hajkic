<?php

declare(strict_types=1);

namespace Hajkic\Geo;

use XMLReader;

/**
 * Streaming GPX 1.0/1.1 parser.
 *
 * Supports multiple <trk>, multiple <trkseg>, <rte> routes and <wpt> waypoints.
 * Never invents geometry: a file with no usable points is reported as invalid.
 */
final class GpxParser
{
    /**
     * @return array{
     *   ok:bool,error:?string,tracks:list<array{name:?string,segments:list<array{name:?string,points:list<array<string,mixed>>}>}>,
     *   waypoints:list<array<string,mixed>>,metadata:array<string,mixed>,
     *   has_elevation:bool,counts:array{points:int,tracks:int,segments:int,waypoints:int},
     *   bbox:?array{0:float,1:float,2:float,3:float}
     * }
     */
    public function parse(string $xml): array
    {
        $empty = [
            'ok'            => false,
            'error'         => null,
            'tracks'        => [],
            'waypoints'     => [],
            'metadata'      => [],
            'has_elevation' => false,
            'counts'        => ['points' => 0, 'tracks' => 0, 'segments' => 0, 'waypoints' => 0],
            'bbox'          => null,
        ];

        if (trim($xml) === '') {
            return [...$empty, 'error' => 'GPX je prazan.'];
        }

        if (!class_exists(XMLReader::class)) {
            return [...$empty, 'error' => 'PHP XMLReader ekstenzija nije dostupna.'];
        }

        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader();
        $loaded = $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [...$empty, 'error' => 'GPX nije validan XML.'];
        }

        $tracks = [];
        $waypoints = [];
        $metadata = [];
        $currentTrack = null;
        $currentTrackDepth = 0;
        $currentSegment = null;
        $currentSegmentDepth = 0;
        $rootSeen = false;

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $name = $reader->localName;

                    if ($name === 'gpx') {
                        $rootSeen = true;
                        $version = $reader->getAttribute('version');
                        $creator = $reader->getAttribute('creator');
                        if ($version !== null) {
                            $metadata['version'] = $version;
                        }
                        if ($creator !== null) {
                            $metadata['creator'] = $creator;
                        }
                        continue;
                    }

                    if ($name === 'metadata') {
                        // Metadata is small; read its direct name/time children.
                        if (!$reader->isEmptyElement) {
                            $depth = $reader->depth;
                            while ($reader->read()) {
                                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === 'metadata') {
                                    break;
                                }
                                if ($reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['name', 'time', 'desc'], true)) {
                                    $value = trim($reader->readString());
                                    if ($value !== '') {
                                        $metadata[$reader->localName] = hajkic_clean_text($value);
                                    }
                                }
                            }
                        }
                        continue;
                    }

                    if ($name === 'trk') {
                        $currentTrack = ['name' => null, 'segments' => []];
                        $currentTrackDepth = $reader->depth;
                        $currentSegment = null;
                        continue;
                    }

                    if ($name === 'rte') {
                        $currentTrack = ['name' => null, 'segments' => [['name' => null, 'points' => []]]];
                        $currentTrackDepth = $reader->depth;
                        $currentSegment = null;
                        continue;
                    }

                    if ($name === 'trkseg' && $currentTrack !== null) {
                        $currentSegment = ['name' => null, 'points' => []];
                        $currentSegmentDepth = $reader->depth;
                        continue;
                    }

                    if ($name === 'trkpt' && $currentTrack !== null) {
                        if ($currentSegment === null) {
                            $currentSegment = ['name' => null, 'points' => []];
                            $currentSegmentDepth = $reader->depth;
                        }
                        $point = $this->readPoint($reader, 'trkpt');
                        if ($point !== null) {
                            $currentSegment['points'][] = $point;
                        }
                        continue;
                    }

                    if ($name === 'rtept' && $currentTrack !== null) {
                        $point = $this->readPoint($reader, 'rtept');
                        if ($point !== null) {
                            $currentTrack['segments'][0]['points'][] = $point;
                        }
                        continue;
                    }

                    if ($name === 'wpt') {
                        $point = $this->readPoint($reader, 'wpt');
                        if ($point !== null) {
                            $waypoints[] = $point;
                        }
                        continue;
                    }

                    if ($name === 'name' && $currentTrack !== null && $currentSegment === null && $reader->depth === $currentTrackDepth + 1) {
                        $value = hajkic_clean_text($reader->readString());
                        if ($value !== null) {
                            $currentTrack['name'] = $value;
                        }
                        continue;
                    }

                    if ($name === 'name' && $currentSegment !== null && $reader->depth === $currentSegmentDepth + 1) {
                        $value = hajkic_clean_text($reader->readString());
                        if ($value !== null) {
                            $currentSegment['name'] = $value;
                        }
                        continue;
                    }
                } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                    $name = $reader->localName;

                    if ($name === 'trkseg' && $currentSegment !== null && $currentTrack !== null) {
                        if ($currentSegment['points'] !== []) {
                            $currentTrack['segments'][] = $currentSegment;
                        }
                        $currentSegment = null;
                        continue;
                    }

                    if (($name === 'trk' || $name === 'rte') && $currentTrack !== null) {
                        // A route built from <rte> already has one segment.
                        if ($currentSegment !== null && $currentSegment['points'] !== []) {
                            $currentTrack['segments'][] = $currentSegment;
                            $currentSegment = null;
                        }
                        if ($currentTrack['segments'] !== []) {
                            $tracks[] = $currentTrack;
                        }
                        $currentTrack = null;
                    }
                }
            }
        } catch (\Throwable $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();

            return [...$empty, 'error' => 'Greška pri čitanju GPX-a: ' . $e->getMessage()];
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $reader->close();

        if (!$rootSeen) {
            return [...$empty, 'error' => 'Datoteka nema <gpx> koren.'];
        }

        // Remove obviously placeholder elevations (PSS waypoints often use 0).
        $this->sanitizeElevations($tracks, $waypoints);

        $routeElevations = [];
        $pointCount = 0;
        $segmentCount = 0;
        $allCoordinates = [];

        foreach ($tracks as $track) {
            foreach ($track['segments'] as $segment) {
                $segmentCount++;
                foreach ($segment['points'] as $point) {
                    $pointCount++;
                    $allCoordinates[] = [$point['lon'], $point['lat']];
                    if ($point['ele'] !== null) {
                        $routeElevations[] = $point['ele'];
                    }
                }
            }
        }

        $hasElevation = false;
        if (count($routeElevations) >= 5 && $pointCount > 0 && count($routeElevations) / $pointCount >= 0.5) {
            $range = max($routeElevations) - min($routeElevations);
            $hasElevation = $range >= 5.0;
        }

        if ($pointCount === 0) {
            return [
                ...$empty,
                'error'     => 'GPX ne sadrži nijednu ispravnu tačku staze.',
                'waypoints' => $waypoints,
                'metadata'  => $metadata,
            ];
        }

        $bbox = Geo::bbox($allCoordinates);

        $firstError = null;
        foreach ($errors as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                $firstError = trim($error->message);
                break;
            }
        }

        return [
            'ok'            => true,
            'error'         => $firstError !== null ? 'Upozorenje XML parsera: ' . $firstError : null,
            'tracks'        => $tracks,
            'waypoints'     => $waypoints,
            'metadata'      => $metadata,
            'has_elevation' => $hasElevation,
            'counts'        => [
                'points'    => $pointCount,
                'tracks'    => count($tracks),
                'segments'  => $segmentCount,
                'waypoints' => count($waypoints),
            ],
            'bbox'          => $bbox,
        ];
    }

    /**
     * Read a trkpt/rtept/wpt and its child fields, leaving the reader on the
     * closing element.
     *
     * @return array<string, mixed>|null
     */
    private function readPoint(XMLReader $reader, string $elementName): ?array
    {
        $lat = $reader->getAttribute('lat');
        $lon = $reader->getAttribute('lon');
        if ($lat === null || $lon === null || !is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        $latF = (float) $lat;
        $lonF = (float) $lon;
        if ($latF < -90 || $latF > 90 || $lonF < -180 || $lonF > 180) {
            return null;
        }
        if (abs($latF) < 0.000001 && abs($lonF) < 0.000001) {
            return null;
        }

        $point = [
            'lat'   => $latF,
            'lon'   => $lonF,
            'ele'   => null,
            'time'  => null,
            'name'  => null,
            'desc'  => null,
            'cmt'   => null,
            'sym'   => null,
        ];

        $depth = $reader->depth;

        if (!$reader->isEmptyElement) {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->localName === $elementName) {
                    break;
                }

                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $child = $reader->localName;
                    if (!in_array($child, ['ele', 'time', 'name', 'desc', 'cmt', 'sym'], true)) {
                        continue;
                    }

                    $value = trim($reader->readString());
                    if ($value === '') {
                        continue;
                    }

                    if ($child === 'ele') {
                        $ele = is_numeric($value) ? (float) $value : null;
                        if ($ele !== null && $ele >= -500 && $ele <= 9000) {
                            $point['ele'] = $ele;
                        }
                    } elseif ($child === 'time') {
                        $point['time'] = $value;
                    } else {
                        $point[$child] = hajkic_clean_text($value);
                    }
                }
            }
        }

        return $point;
    }

    /**
     * PSS GPX files frequently use elevation 0 for waypoints and some points.
     * If the route clearly sits above sea level, zero is a placeholder.
     */
    private function sanitizeElevations(array &$tracks, array &$waypoints): void
    {
        $values = [];
        foreach ($tracks as $track) {
            foreach ($track['segments'] as $segment) {
                foreach ($segment['points'] as $point) {
                    if ($point['ele'] !== null && $point['ele'] > 0) {
                        $values[] = $point['ele'];
                    }
                }
            }
        }

        if ($values === []) {
            return;
        }

        sort($values);
        $median = $values[intdiv(count($values), 2)];
        if ($median <= 30) {
            return;
        }

        foreach ($tracks as &$track) {
            foreach ($track['segments'] as &$segment) {
                foreach ($segment['points'] as &$point) {
                    if ($point['ele'] !== null && $point['ele'] == 0.0) {
                        $point['ele'] = null;
                    }
                }
            }
            unset($segment, $point);
        }
        unset($track);

        foreach ($waypoints as &$waypoint) {
            if ($waypoint['ele'] !== null && $waypoint['ele'] == 0.0) {
                $waypoint['ele'] = null;
            }
        }
        unset($waypoint);
    }

    /**
     * Flatten tracks to a stable segment list used by statistics and GeoJSON.
     *
     * @param array<string,mixed> $parsed
     * @return list<array{track:int,segment:int,name:?string,points:list<array<string,mixed>>}>
     */
    public static function flatSegments(array $parsed): array
    {
        $segments = [];
        foreach ((array) ($parsed['tracks'] ?? []) as $trackIndex => $track) {
            foreach ((array) ($track['segments'] ?? []) as $segmentIndex => $segment) {
                $points = (array) ($segment['points'] ?? []);
                if (count($points) < 2) {
                    continue;
                }
                $segments[] = [
                    'track'   => (int) $trackIndex,
                    'segment' => (int) $segmentIndex,
                    'name'    => $segment['name'] ?? $track['name'] ?? null,
                    'points'  => $points,
                ];
            }
        }

        return $segments;
    }
}
