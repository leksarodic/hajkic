<?php

declare(strict_types=1);

namespace Hajkic\Geo;

/**
 * Elevation analysis with light smoothing and a hysteresis threshold.
 *
 * Raw GPS elevation is noisy; summing every positive delta would report a
 * fantasy ascent. We smooth each contiguous elevation run and only commit a
 * change once it exceeds a small threshold. The result is labelled "calculated"
 * everywhere in the app and never replaces official PSS values.
 */
final class Elevation
{
    public function __construct(
        private readonly int $smoothWindow = 5,
        private readonly float $thresholdMeters = 1.5,
    ) {
    }

    /**
     * @param list<float|null> $elevations
     * @return array{gain_m:?float,loss_m:?float,min_m:?float,max_m:?float,valid:int,total:int}
     */
    public function analyse(array $elevations): array
    {
        $valid = array_values(array_filter($elevations, static fn ($v) => $v !== null && is_finite((float) $v)));
        $total = count($elevations);

        if ($valid === []) {
            return ['gain_m' => null, 'loss_m' => null, 'min_m' => null, 'max_m' => null, 'valid' => 0, 'total' => $total];
        }

        $gain = 0.0;
        $loss = 0.0;
        $run = [];

        foreach ($elevations as $value) {
            if ($value === null || !is_finite((float) $value)) {
                if (count($run) >= 2) {
                    [$g, $l] = $this->gainLossRun($run);
                    $gain += $g;
                    $loss += $l;
                }
                $run = [];
                continue;
            }
            $run[] = (float) $value;
        }

        if (count($run) >= 2) {
            [$g, $l] = $this->gainLossRun($run);
            $gain += $g;
            $loss += $l;
        }

        return [
            'gain_m' => round($gain, 1),
            'loss_m' => round($loss, 1),
            'min_m'  => round(min($valid), 1),
            'max_m'  => round(max($valid), 1),
            'valid'  => count($valid),
            'total'  => $total,
        ];
    }

    /**
     * @param list<float> $run
     * @return array{0:float,1:float}
     */
    private function gainLossRun(array $run): array
    {
        // Short runs lose too much shape under a moving average; use raw values.
        if (count($run) >= 10) {
            $run = $this->smooth($run);
        }
        $gain = 0.0;
        $loss = 0.0;
        $reference = $run[0];

        foreach ($run as $value) {
            $diff = $value - $reference;
            if ($diff > $this->thresholdMeters) {
                $gain += $diff;
                $reference = $value;
            } elseif ($diff < -$this->thresholdMeters) {
                $loss += abs($diff);
                $reference = $value;
            }
        }

        return [$gain, $loss];
    }

    /**
     * Centered moving average, preserving the run length.
     *
     * @param list<float> $values
     * @return list<float>
     */
    private function smooth(array $values): array
    {
        $count = count($values);
        if ($count < 3 || $this->smoothWindow < 3) {
            return $values;
        }

        $half = intdiv($this->smoothWindow, 2);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $sum = 0.0;
            $n = 0;
            for ($j = max(0, $i - $half); $j <= min($count - 1, $i + $half); $j++) {
                $sum += $values[$j];
                $n++;
            }
            $result[] = $sum / $n;
        }

        return $result;
    }
}
