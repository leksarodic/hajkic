<?php

declare(strict_types=1);

namespace Hajkic\Seo;

use GdImage;

/**
 * Server-side social/SEO image renderer. Pure GD: no external API, no queue.
 */
final class OgImage
{
    private string $fontDisplay;
    private string $fontBody;
    private string $fontMono;

    public function __construct()
    {
        $this->fontDisplay = HAJKIC_ROOT . '/assets/fonts/InstrumentSerif-Regular.ttf';
        $this->fontBody = HAJKIC_ROOT . '/assets/fonts/InterTight-Variable.ttf';
        $this->fontMono = HAJKIC_ROOT . '/assets/fonts/IBMPlexMono-Regular.ttf';
    }

    /**
     * @param array<string,mixed>|null $trail
     */
    public function render(?array $trail = null): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD ekstenzija nije dostupna.');
        }

        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);
        if (!$img instanceof GdImage) {
            throw new \RuntimeException('Ne mogu da napravim sliku.');
        }

        $paper = imagecolorallocate($img, 240, 245, 239);
        $surface = imagecolorallocate($img, 255, 255, 255);
        $forest = imagecolorallocate($img, 29, 90, 59);
        $forest700 = imagecolorallocate($img, 21, 70, 45);
        $ink = imagecolorallocate($img, 20, 35, 26);
        $soft = imagecolorallocate($img, 75, 93, 80);
        $faint = imagecolorallocate($img, 113, 129, 118);
        $line = imagecolorallocate($img, 210, 221, 210);
        $accent = imagecolorallocate($img, 207, 92, 40);
        $sage = imagecolorallocate($img, 226, 234, 225);

        imagefill($img, 0, 0, $paper);

        // Editorial contour field.
        imagesetthickness($img, 1);
        for ($i = 0; $i < 7; $i++) {
            $cw = 460 + $i * 70;
            $ch = 300 + $i * 48;
            imageellipse($img, 940, 310, $cw, $ch, $line);
            imageellipse($img, 940, 310, $cw - 26, $ch - 24, $line);
        }

        // Top rule and wordmark.
        $this->text($img, 64, 79, 27, $forest, $this->fontMono, 'HAJKIĆ  /  STAZE SRBIJE');
        imagesetthickness($img, 3);
        imageline($img, 64, 100, 116, 100, $accent);

        if ($trail === null) {
            $this->renderDefault($img, $w, $h, $forest, $forest700, $ink, $soft, $faint, $surface, $accent);
        } else {
            $this->renderTrail($img, $trail, $w, $h, $forest, $forest700, $ink, $soft, $faint, $surface, $accent);
        }

        $host = (string) (parse_url(hajkic_absolute_url('/'), PHP_URL_HOST) ?: 'hajkic');
        $this->text($img, 64, 586, 14, $faint, $this->fontMono, 'Podaci: Planinarski savez Srbije · ' . $host);
        $this->text($img, 64, 608, 12, $faint, $this->fontBody, 'Nezavisan projekat. Proverite uslove na terenu pre polaska.');

        ob_start();
        imagepng($img, null, 6);
        $binary = (string) ob_get_clean();

        return $binary;
    }

    private function renderDefault(GdImage $img, int $w, int $h, int $forest, int $forest700, int $ink, int $soft, int $faint, int $surface, int $accent): void
    {
        $this->text($img, 64, 190, 72, $ink, $this->fontDisplay, 'Planinarske staze');
        $this->text($img, 64, 262, 72, $ink, $this->fontDisplay, 'Srbije, na jednom');
        $this->text($img, 64, 334, 72, $ink, $this->fontDisplay, 'mestu.');
        $this->text($img, 64, 386, 21, $soft, $this->fontBody, 'Interaktivna mapa, stvarna GPX geometrija i visinski profili.');
        $this->text($img, 64, 424, 21, $soft, $this->fontBody, 'Otvoreno, bez reklama i bez izmišljenih podataka.');

        $this->pill($img, 64, 462, 280, 46, $forest, 'Otvori mapu');
        $this->text($img, 357, 490, 13, $faint, $this->fontMono, 'INTERAKTIVNA MAPA');
    }

    /**
     * @param array<string,mixed> $trail
     */
    private function renderTrail(GdImage $img, array $trail, int $w, int $h, int $forest, int $forest700, int $ink, int $soft, int $faint, int $surface, int $accent): void
    {
        $this->text($img, 64, 166, 14, $forest, $this->fontMono, 'PLANINARSKA STAZA' . (!empty($trail['code']) ? '  ·  ' . mb_strtoupper((string) $trail['code'], 'UTF-8') : ''));

        $title = (string) ($trail['name'] ?? 'Planinarska staza');
        $lines = $this->wrap($title, 54, $this->fontDisplay, 590);
        $y = 224;
        foreach (array_slice($lines, 0, 3) as $line) {
            $this->text($img, 64, $y, 54, $ink, $this->fontDisplay, $line);
            $y += 58;
        }

        $place = trim(implode(' · ', array_filter([
            $trail['mountain'] ?? null,
            $trail['region'] ?? null,
        ])));
        if ($place !== '') {
            $this->text($img, 64, $y + 14, 20, $soft, $this->fontBody, $place);
        }

        $distance = $trail['distance']['official_km'] ?? $trail['distance']['calculated_km'] ?? null;
        $duration = $trail['duration']['official_text'] ?? null;
        $difficulty = $trail['difficulty']['label'] ?? null;
        $stats = array_filter([
            $distance !== null ? hajkic_format_number((float) $distance, 1) . ' km' : null,
            $difficulty,
            $duration,
        ]);
        $statsText = implode('   ·   ', $stats);
        if ($statsText !== '') {
            $this->text($img, 64, 508, 18, $forest700, $this->fontMono, $statsText);
        }

        if (!empty($trail['gpx']['available'])) {
            $this->pill($img, 64, 530, 190, 38, $forest, 'GPX dostupan');
        }

        $this->drawRoute($img, $trail, 680, 128, 450, 390, $forest, $accent);
    }

    /**
     * @param array<string,mixed> $trail
     */
    private function drawRoute(GdImage $img, array $trail, int $x, int $y, int $w, int $h, int $forest, int $accent): void
    {
        $repo = new \Hajkic\Data\TrailRepository();
        $slug = (string) ($trail['slug'] ?? '');
        if ($slug === '') {
            return;
        }
        $geojson = $repo->loadGeojson($slug);
        $bbox = $trail['geometry']['bbox'] ?? null;
        if ($geojson === null || !is_array($bbox)) {
            return;
        }

        // Card panel.
        $panel = imagecolorallocate($img, 232, 240, 231);
        imagefilledrectangle($img, $x - 24, $y - 24, $x + $w + 24, $y + $h + 24, $panel);
        imagesetthickness($img, 1);
        imagerectangle($img, $x - 24, $y - 24, $x + $w + 24, $y + $h + 24, imagecolorallocate($img, 210, 221, 210));

        $minLon = (float) $bbox[0];
        $minLat = (float) $bbox[1];
        $maxLon = (float) $bbox[2];
        $maxLat = (float) $bbox[3];
        $spanLon = max(0.0001, $maxLon - $minLon);
        $spanLat = max(0.0001, $maxLat - $minLat);
        $pad = 22;
        $usableW = $w - $pad * 2;
        $usableH = $h - $pad * 2;
        $start = null;
        $end = null;

        foreach ($geojson['features'] as $feature) {
            if (($feature['properties']['kind'] ?? '') !== 'trail' || ($feature['geometry']['type'] ?? '') !== 'LineString') {
                continue;
            }
            $coords = $feature['geometry']['coordinates'] ?? [];
            if (count($coords) < 2) {
                continue;
            }
            $points = [];
            foreach ($coords as $coord) {
                $px = (int) round($x + $pad + (((float) $coord[0] - $minLon) / $spanLon) * $usableW);
                $py = (int) round($y + $pad + (($maxLat - (float) $coord[1]) / $spanLat) * $usableH);
                $points[] = [$px, $py];
            }
            if ($start === null) {
                $start = $points[0];
            }
            $end = $points[count($points) - 1];

            imagesetthickness($img, 10);
            for ($i = 1; $i < count($points); $i++) {
                imageline($img, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], imagecolorallocate($img, 255, 255, 255));
            }
            imagesetthickness($img, 5);
            for ($i = 1; $i < count($points); $i++) {
                imageline($img, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $accent);
            }
        }

        if ($start !== null) {
            imagefilledellipse($img, $start[0], $start[1], 18, 18, imagecolorallocate($img, 255, 255, 255));
            imagefilledellipse($img, $start[0], $start[1], 11, 11, $forest);
        }
        if ($end !== null) {
            imagefilledrectangle($img, $end[0] - 6, $end[1] - 6, $end[0] + 6, $end[1] + 6, $accent);
            imagesetthickness($img, 2);
            imagerectangle($img, $end[0] - 6, $end[1] - 6, $end[0] + 6, $end[1] + 6, imagecolorallocate($img, 255, 255, 255));
        }
    }

    private function pill(GdImage $img, int $x, int $y, int $w, int $h, int $color, string $label): void
    {
        imagefilledrectangle($img, $x, $y, $x + $w, $y + $h, $color);
        $this->text($img, $x + 18, $y + $h - 14, 13, imagecolorallocate($img, 255, 255, 255), $this->fontMono, mb_strtoupper($label, 'UTF-8'));
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $size, string $font, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $box = imagettfbbox($size, 0, $font, $candidate);
            $width = $box !== false ? abs($box[2] - $box[0]) : 0;
            if ($width > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    private function text(GdImage $img, int $x, int $y, int $size, int $color, string $font, string $text): void
    {
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }
}
