<?php

declare(strict_types=1);

namespace Hajkic\Geo;

/**
 * Douglas–Peucker simplification in a local metric projection.
 */
final class Simplifier
{
    /**
     * @param list<array{0:float,1:float,2?:float|null}> $coordinates [lon, lat, ele?]
     * @return list<array{0:float,1:float,2?:float|null}>
     */
    public static function simplify(array $coordinates, float $toleranceMeters, int $maxPoints = 0): array
    {
        $count = count($coordinates);
        if ($count <= 2 || $toleranceMeters <= 0) {
            return $coordinates;
        }

        $result = self::douglasPeucker($coordinates, $toleranceMeters);

        // If a very long track is still too heavy, simplify harder rather than
        // dropping the tail or breaking the line.
        $guard = 0;
        while ($maxPoints > 0 && count($result) > $maxPoints && $guard < 8) {
            $toleranceMeters *= 1.7;
            $result = self::douglasPeucker($coordinates, $toleranceMeters);
            $guard++;
        }

        return $result;
    }

    /**
     * @param list<array{0:float,1:float,2?:float|null}> $points
     * @return list<array{0:float,1:float,2?:float|null}>
     */
    private static function douglasPeucker(array $points, float $toleranceMeters): array
    {
        $count = count($points);
        if ($count <= 2) {
            return $points;
        }

        $lat0 = deg2rad((float) $points[0][1]);
        $mPerDegLat = 110_574.0;
        $mPerDegLon = 111_320.0 * cos($lat0);

        $hasZ = false;
        foreach ($points as $point) {
            if (isset($point[2]) && is_numeric($point[2])) {
                $hasZ = true;
                break;
            }
        }

        $xyz = [];
        foreach ($points as $point) {
            $xyz[] = [
                ((float) $point[0]) * $mPerDegLon,
                ((float) $point[1]) * $mPerDegLat,
                $hasZ && isset($point[2]) && is_numeric($point[2]) ? (float) $point[2] : 0.0,
            ];
        }

        $keep = array_fill(0, $count, false);
        $keep[0] = true;
        $keep[$count - 1] = true;
        $stack = [[0, $count - 1]];
        $toleranceSquared = $toleranceMeters * $toleranceMeters;

        while ($stack !== []) {
            [$start, $end] = array_pop($stack);
            $maxDistance = 0.0;
            $maxIndex = -1;
            [$x1, $y1, $z1] = $xyz[$start];
            [$x2, $y2, $z2] = $xyz[$end];
            $dx = $x2 - $x1;
            $dy = $y2 - $y1;
            $dz = $z2 - $z1;
            $denominator = $dx * $dx + $dy * $dy + $dz * $dz;

            for ($i = $start + 1; $i < $end; $i++) {
                [$px, $py, $pz] = $xyz[$i];

                if ($denominator == 0.0) {
                    $distanceSquared = ($px - $x1) ** 2 + ($py - $y1) ** 2 + ($pz - $z1) ** 2;
                } else {
                    $t = (($px - $x1) * $dx + ($py - $y1) * $dy + ($pz - $z1) * $dz) / $denominator;
                    $t = max(0.0, min(1.0, $t));
                    $projX = $x1 + $t * $dx;
                    $projY = $y1 + $t * $dy;
                    $projZ = $z1 + $t * $dz;
                    $distanceSquared = ($px - $projX) ** 2 + ($py - $projY) ** 2 + ($pz - $projZ) ** 2;
                }

                if ($distanceSquared > $maxDistance) {
                    $maxDistance = $distanceSquared;
                    $maxIndex = $i;
                }
            }

            if ($maxIndex !== -1 && $maxDistance > $toleranceSquared) {
                $keep[$maxIndex] = true;
                $stack[] = [$start, $maxIndex];
                $stack[] = [$maxIndex, $end];
            }
        }

        $result = [];
        foreach ($keep as $index => $kept) {
            if ($kept) {
                $result[] = $points[$index];
            }
        }

        return $result;
    }
}
