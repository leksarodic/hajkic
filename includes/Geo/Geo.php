<?php

declare(strict_types=1);

namespace Hajkic\Geo;

/**
 * Great-circle distance helpers.
 */
final class Geo
{
    public const EARTH_RADIUS_M = 6371008.8;

    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
        $a = min(1.0, max(0.0, $a));

        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Path length for a list of [lon, lat] or [lon, lat, ele] positions.
     */
    public static function pathLengthMeters(array $coordinates): float
    {
        $total = 0.0;
        $count = count($coordinates);
        if ($count < 2) {
            return 0.0;
        }

        for ($i = 1; $i < $count; $i++) {
            $prev = $coordinates[$i - 1];
            $curr = $coordinates[$i];
            $total += self::haversineMeters((float) $prev[1], (float) $prev[0], (float) $curr[1], (float) $curr[0]);
        }

        return $total;
    }

    /**
     * Bounding box [west, south, east, north] for positions.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    public static function bbox(array $coordinates): ?array
    {
        if ($coordinates === []) {
            return null;
        }

        $minLon = $maxLon = (float) $coordinates[0][0];
        $minLat = $maxLat = (float) $coordinates[0][1];

        foreach ($coordinates as $coord) {
            $lon = (float) $coord[0];
            $lat = (float) $coord[1];
            $minLon = min($minLon, $lon);
            $maxLon = max($maxLon, $lon);
            $minLat = min($minLat, $lat);
            $maxLat = max($maxLat, $lat);
        }

        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    public static function center(array $bbox): array
    {
        return [round(($bbox[0] + $bbox[2]) / 2, 6), round(($bbox[1] + $bbox[3]) / 2, 6)];
    }
}
