<?php

declare(strict_types=1);

namespace Hajkic\Pss;

/**
 * Parser for the structured fragments embedded by the PSS WordPress theme.
 *
 * PSS renders a `var terrainsObj = {...}` object in both register and trail
 * pages. It is far more stable than scraping table cells, so it is the primary
 * source. HTML links are used only to cross-check the GPX URL and canonical URL.
 */
final class PssParser
{
    private const OBJECT_MARKER = 'var terrainsObj = ';

    /**
     * Extract the terrainsObj JSON object from a page.
     *
     * @return array<string, mixed>|null
     */
    public function extractObject(string $html): ?array
    {
        $start = strpos($html, self::OBJECT_MARKER);
        if ($start === false) {
            return null;
        }

        $json = $this->extractBalancedJson($html, $start + strlen(self::OBJECT_MARKER));
        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Parse the register page payload.
     *
     * @return array{posts: list<array<string,mixed>>, terrains: list<array<string,mixed>>, regions: list<array<string,mixed>>, types: array<string,mixed>, sitemap: list<array{url:string,lastmod:?string}>}
     */
    public function parseRegister(string $html, ?string $sitemapXml = null): array
    {
        $object = $this->extractObject($html);
        if ($object === null) {
            throw new \RuntimeException('Registar ne sadrži terrainsObj podatke.');
        }

        return [
            'posts'    => array_values((array) ($object['posts'] ?? [])),
            'terrains' => array_values((array) ($object['terrains'] ?? [])),
            'regions'  => array_values((array) ($object['geoRegions'] ?? [])),
            'types'    => (array) ($object['types'] ?? []),
            'sitemap'  => $sitemapXml !== null ? $this->parseSitemap($sitemapXml) : [],
        ];
    }

    /**
     * Parse the sitemap for `/terenipp/` pages, with last modification dates.
     *
     * @return list<array{url:string,lastmod:?string}>
     */
    public function parseSitemap(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $entries = [];
        $urls = $dom->getElementsByTagName('url');

        foreach ($urls as $urlNode) {
            $loc = null;
            $lastmod = null;
            foreach ($urlNode->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                if ($child->localName === 'loc') {
                    $loc = trim($child->textContent);
                } elseif ($child->localName === 'lastmod') {
                    $lastmod = hajkic_iso_date(trim($child->textContent));
                }
            }

            if ($loc !== null && str_contains($loc, '/terenipp/')) {
                $entries[] = ['url' => $loc, 'lastmod' => $lastmod];
            }
        }

        return $entries;
    }

    /**
     * Parse one `/terenipp/{slug}/` page.
     *
     * @return array<string, mixed>
     */
    public function parseTrailPage(string $html, string $requestedUrl): array
    {
        $object = $this->extractObject($html);
        $post = null;

        if ($object !== null) {
            $post = isset($object['post']) && is_array($object['post'])
                ? $object['post']
                : (array_key_exists('ID', $object) ? $object : null);
        }

        $canonical = $this->extractCanonical($html);
        $gpxUrls = $this->extractGpxUrls($html, $post);

        return [
            'post'               => $post,
            'canonical_url'      => $canonical ?? hajkic_normalize_url($requestedUrl),
            'requested_url'      => hajkic_normalize_url($requestedUrl),
            'html_title'         => $this->extractTitle($html),
            'source_updated_at'  => $this->extractUpdatedAt($html),
            'published_at'       => $this->extractPublishedAt($html),
            'gpx_urls'           => $gpxUrls,
            'has_structured'     => $post !== null && isset($post['postmeta']) && is_array($post['postmeta']),
        ];
    }

    /**
     * Map one PSS post + embedded postmeta to Hajkić's raw trail fields.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $context  regions, terrains, types
     * @return array<string, mixed>
     */
    public function mapPost(array $post, array $context, array $gpxUrls = []): array
    {
        $meta = [];
        foreach ((array) ($post['postmeta'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['label'])) {
                continue;
            }
            $label = $this->cleanLabel((string) $entry['label']);
            $meta[$label] = $entry;
        }

        $regionId = isset($post['region_ids'][0]) ? (string) $post['region_ids'][0] : null;
        $regionName = $this->regionName($meta['Region'] ?? null, $regionId, (array) ($context['regions'] ?? []));
        $terrainIds = array_values(array_map('strval', (array) ($post['terrain_ids'] ?? [])));
        $mountain = $this->textValue($meta['Planina/predeo'] ?? null);
        if (($mountain === null || $mountain === '') && $terrainIds !== []) {
            $mountain = $this->mountainName($terrainIds[0], (array) ($context['terrains'] ?? []));
        }

        $club = $this->clubValue($meta['Društvo/klub'] ?? $meta['Društva/klubovi'] ?? null);
        $distance = $this->textValue($meta['Dužina (km)'] ?? null);
        $durationText = $this->textValue($meta['Vreme obilaska (h:min)'] ?? null);
        $elevationText = $this->textValue($meta['Visinska razlika: uspon/spust (m)'] ?? null);
        $elevation = $this->parseElevationPair($elevationText);
        $difficultyText = $this->textValue($meta['Težina staze'] ?? $meta['Težina'] ?? null);
        $categoryId = isset($post['terrain_category']) ? (string) $post['terrain_category'] : null;
        $category = $this->categoryInfo($categoryId, (array) ($context['types'] ?? []));

        $gpxUrl = null;
        if (isset($meta['GPX'])) {
            $gpxUrl = $this->extractGpxUrlFromMeta($meta['GPX']);
        }
        if ($gpxUrl === null && $gpxUrls !== []) {
            $gpxUrl = $gpxUrls[0];
        }

        $photos = $meta['Fotografije'] ?? null;
        $photosCount = 0;
        if (is_array($photos)) {
            $value = $photos['value'] ?? [];
            $photosCount = is_array($value) ? count($value) : 0;
        }

        $legend = [];
        if (isset($meta['Skraćenice'])) {
            $legendHtml = (string) ($meta['Skraćenice']['value'] ?? $meta['Skraćenice']['getValue'] ?? '');
            if (preg_match_all('~<li[^>]*>(.*?)</li>~is', $legendHtml, $matches)) {
                foreach ($matches[1] as $item) {
                    $line = hajkic_html_to_text($item);
                    if ($line !== null) {
                        $legend[] = $line;
                    }
                }
            }
        }

        $mappedLabels = [
            'Region', 'Oznaka', 'Planina/predeo', 'Društvo/klub', 'Karta', 'Dužina (km)',
            'Vreme obilaska (h:min)', 'Početak staze', 'Kraj staze',
            'Visinska razlika: uspon/spust (m)', 'Opis', 'Težina staze', 'Uređenost',
            'Vrsta markacije', 'Značajni lokaliteti', 'Smeštaj i snadbevenost', 'Moguće prenoćište', 'Težina', 'Društva/klubovi', 'Deonice',
            'Napomena', 'GPX', 'Fotografije', 'Skraćenice', 'Mapa',
        ];

        $extra = [];
        foreach ($meta as $label => $entry) {
            if (in_array($label, $mappedLabels, true)) {
                continue;
            }
            $value = $this->textValue($entry);
            if ($value !== null) {
                $extra[$label] = $value;
            }
        }

        return [
            'pss_id'                 => isset($post['ID']) ? (int) $post['ID'] : null,
            'name'                   => hajkic_clean_text((string) ($post['title'] ?? '')) ?? '',
            'code'                   => $this->textValue($meta['Oznaka'] ?? null),
            'category_id'            => $categoryId,
            'category_key'           => $category['key'],
            'category_label'         => $category['label'],
            'region_id'              => $regionId,
            'region_name'            => $regionName,
            'mountain'               => hajkic_clean_text($mountain),
            'mountain_ids'           => array_map('intval', $terrainIds),
            'difficulty'             => $this->normalizeDifficulty($difficultyText),
            'distance_official_km'   => hajkic_parse_number($distance),
            'duration'               => $this->parseDuration($durationText),
            'elevation_official'     => $elevation,
            'start'                  => $this->textValue($meta['Početak staze'] ?? null),
            'end'                    => $this->textValue($meta['Kraj staze'] ?? null),
            'description'            => $this->richTextValue($meta['Opis'] ?? null),
            'landmarks'              => $this->richTextValue($meta['Značajni lokaliteti'] ?? null),
            'maintenance'            => $this->textValue($meta['Uređenost'] ?? null),
            'marking'                => $this->textValue($meta['Vrsta markacije'] ?? null),
            'accommodation'          => $this->richTextValue($meta['Smeštaj i snadbevenost'] ?? $meta['Moguće prenoćište'] ?? null),
            'notes'                  => $this->richTextValue($meta['Napomena'] ?? null) ?? $this->richTextValue($meta['Deonice'] ?? null),
            'map_reference'          => $this->richTextValue($meta['Mapa'] ?? null),
            'map_sheet'              => $this->textValue($meta['Karta'] ?? null),
            'club'                   => $club,
            'legend'                 => $legend,
            'photos_count'           => $photosCount,
            'gpx_url'                => $gpxUrl,
            'extra'                  => $extra,
        ];
    }

    /**
     * Normalise a label: trim, strip colon.
     */
    private function cleanLabel(string $label): string
    {
        return trim(rtrim(trim($label), ':'));
    }

    /**
     * Extract a balanced JSON object starting at $offset.
     * Handles braces inside strings and escaped quotes.
     */
    private function extractBalancedJson(string $text, int $offset): ?string
    {
        $length = strlen($text);
        if ($offset < 0 || $offset >= $length || $text[$offset] !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = $offset; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $offset, $i - $offset + 1);
                }
            }
        }

        return null;
    }

    private function extractCanonical(string $html): ?string
    {
        if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]*>~i', $html, $match)) {
            if (preg_match('~href=["\']([^"\']+)["\']~i', $match[0], $href)) {
                return hajkic_normalize_url(html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return null;
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match)) {
            return hajkic_html_to_text($match[1]);
        }

        return null;
    }

    private function extractUpdatedAt(string $html): ?string
    {
        foreach ([
            '~<meta[^>]+property=["\']og:updated_time["\'][^>]+content=["\']([^"\']+)["\']~i',
            '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:updated_time["\']~i',
            '~<meta[^>]+property=["\']article:modified_time["\'][^>]+content=["\']([^"\']+)["\']~i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                return hajkic_iso_date($match[1]);
            }
        }

        return null;
    }

    private function extractPublishedAt(string $html): ?string
    {
        foreach ([
            '~<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']~i',
            '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\']~i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                return hajkic_iso_date($match[1]);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractGpxUrls(string $html, ?array $post = null): array
    {
        $urls = [];

        if ($post !== null && isset($post['postmeta']) && is_array($post['postmeta'])) {
            foreach ($post['postmeta'] as $entry) {
                if (!is_array($entry) || !isset($entry['label'])) {
                    continue;
                }
                if ($this->cleanLabel((string) $entry['label']) === 'GPX') {
                    $url = $this->extractGpxUrlFromMeta($entry);
                    if ($url !== null) {
                        $urls[] = $url;
                    }
                }
            }
        }

        if (preg_match_all('~https?://[^"\'\s<>]+?\.gpx(?:\?[^"\'\s<>]*)?~i', $html, $matches)) {
            foreach ($matches[0] as $url) {
                $normalized = $this->normalizeGpxUrl($url);
                if ($normalized !== null) {
                    $urls[] = $normalized;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private function extractGpxUrlFromMeta(array $entry): ?string
    {
        $candidates = [];
        if (isset($entry['value'])) {
            if (is_string($entry['value'])) {
                $candidates[] = $entry['value'];
            } elseif (is_array($entry['value'])) {
                foreach ($entry['value'] as $value) {
                    if (is_string($value)) {
                        $candidates[] = $value;
                    }
                }
            }
        }
        if (isset($entry['getValue']) && is_string($entry['getValue'])) {
            if (preg_match('~href=["\']([^"\']+)["\']~i', $entry['getValue'], $m)) {
                $candidates[] = $m[1];
            }
        }

        foreach ($candidates as $candidate) {
            if (preg_match('~https?://[^"\'\s<>]+?\.gpx(?:\?[^"\'\s<>]*)?~i', $candidate, $m)) {
                $url = $this->normalizeGpxUrl($m[0]);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    private function normalizeGpxUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = hajkic_normalize_url($url, false);
        if ($normalized === null) {
            return null;
        }

        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
        if (!in_array($host, ['pss.rs', 'www.pss.rs'], true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Pluck a plain text value out of a postmeta entry.
     */
    private function textValue(?array $entry): ?string
    {
        if ($entry === null) {
            return null;
        }

        $value = $entry['value'] ?? $entry['getValue'] ?? null;
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = $item;
                } elseif (is_array($item)) {
                    $parts[] = (string) ($item['post_title'] ?? $item['name'] ?? '');
                }
            }
            $value = implode(', ', array_filter($parts));
        }

        if (!is_string($value)) {
            return null;
        }

        $text = hajkic_html_to_text($value);
        if ($text !== null && preg_match('/^(---+|—+|–+|-+|n\/a|nepoznato|ne postoji|stranica je u pripremi)$/iu', trim($text))) {
            return null;
        }

        return $text;
    }

    private function richTextValue(?array $entry): ?string
    {
        return $this->textValue($entry);
    }

    /**
     * Club entry has a nested array of clubs, each with title, URL and contact HTML.
     *
     * @return array{name:?string,url:?string,contact:?string,clubs:list<array{name:string,url:?string,contact:?string}>}|null
     */
    private function clubValue(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        $clubs = [];
        $raw = $entry['value'] ?? null;

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = hajkic_clean_text((string) ($item['post_title'] ?? ''));
                if ($name === null) {
                    continue;
                }
                $contact = isset($item['meta']) && is_string($item['meta'])
                    ? hajkic_html_to_text($item['meta'])
                    : null;
                $clubUrl = isset($item['permalink']) && is_string($item['permalink'])
                    ? hajkic_normalize_url($item['permalink'])
                    : null;
                $clubs[] = ['name' => $name, 'url' => $clubUrl, 'contact' => $contact];
            }
        }

        if ($clubs === []) {
            $name = $this->textValue($entry);
            if ($name === null) {
                return null;
            }
            $clubs[] = ['name' => $name, 'url' => null, 'contact' => null];
        }

        return [
            'name'    => $clubs[0]['name'],
            'url'     => $clubs[0]['url'],
            'contact' => $clubs[0]['contact'],
            'clubs'   => $clubs,
        ];
    }

    /**
     * @return array{key:string,label:string,source:?string}
     */
    private function normalizeDifficulty(?string $text): array
    {
        if ($text === null || $text === '') {
            return ['key' => 'nepoznato', 'label' => 'Nije navedeno', 'source' => null];
        }

        $lower = mb_strtolower($text, 'UTF-8');
        $key = 'ostalo';
        $label = ucfirst($text);

        if (str_contains($lower, 'vrlo te')) {
            $key = 'vrlo_teska';
            $label = 'Vrlo teška';
        } elseif (str_contains($lower, 'zahtev')) {
            $key = 'zahtevna';
            $label = 'Zahtevna';
        } elseif (str_contains($lower, 'srednje')) {
            $key = 'srednje_teska';
            $label = 'Srednje teška';
        } elseif (str_contains($lower, 'laka') || str_contains($lower, 'lak')) {
            $key = 'laka';
            $label = 'Laka';
        }

        return ['key' => $key, 'label' => $label, 'source' => $text];
    }

    /**
     * @return array{minutes:?int,text:?string,raw:?string}
     */
    private function parseDuration(?string $text): array
    {
        if ($text === null || $text === '') {
            return ['minutes' => null, 'text' => null, 'raw' => null];
        }

        $raw = $text;
        $minutes = null;

        if (preg_match('~^\s*(\d+)\s*:\s*(\d{1,2})\s*$~', $text, $m)) {
            $minutes = ((int) $m[1]) * 60 + min(59, (int) $m[2]);
        } elseif (preg_match('~^\s*(\d+)\s*[.,]\s*(\d{2})\s*$~', $text, $m)) {
            // PSS occasionally enters "2.00" or "5,30" instead of h:min.
            $minutes = ((int) $m[1]) * 60 + min(59, (int) $m[2]);
        } elseif (preg_match('~^\s*(\d+)\s*$~', $text, $m)) {
            $minutes = ((int) $m[1]) * 60;
        } elseif (preg_match('~(\d+)\s*h~i', $text, $m)) {
            $minutes = ((int) $m[1]) * 60;
        }

        if ($minutes === null || $minutes <= 0) {
            return ['minutes' => null, 'text' => $raw, 'raw' => $raw];
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        $display = $hours > 0
            ? $hours . ' h' . ($mins > 0 ? ' ' . str_pad((string) $mins, 2, '0', STR_PAD_LEFT) . ' min' : '')
            : $mins . ' min';

        return ['minutes' => $minutes, 'text' => $display, 'raw' => $raw];
    }

    /**
     * @return array{gain_m:?float,loss_m:?float,raw:?string}
     */
    private function parseElevationPair(?string $text): array
    {
        if ($text === null || $text === '') {
            return ['gain_m' => null, 'loss_m' => null, 'raw' => null];
        }

        $parts = preg_split('~/~', $text) ?: [];
        $gain = isset($parts[0]) ? hajkic_parse_number($parts[0]) : null;
        $loss = isset($parts[1]) ? hajkic_parse_number($parts[1]) : null;

        return ['gain_m' => $gain, 'loss_m' => $loss, 'raw' => $text];
    }

    /**
     * @param list<array<string,mixed>> $regions
     */
    private function regionName(?array $entry, ?string $regionId, array $regions): ?string
    {
        if ($entry !== null) {
            $text = $this->textValue($entry);
            if ($text !== null) {
                // "5 - Jugozapadna Srbija" → keep the full official text as source
                // but prefer the canonical region name from the register.
                if (preg_match('~^\d+\s*-\s*(.+)$~u', $text, $m)) {
                    return hajkic_clean_text($m[1]);
                }
                return $text;
            }
        }

        if ($regionId !== null) {
            foreach ($regions as $region) {
                if (isset($region['id']) && (string) $region['id'] === $regionId) {
                    return hajkic_clean_text((string) ($region['name'] ?? ''));
                }
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $terrains
     */
    private function mountainName(string $id, array $terrains): ?string
    {
        foreach ($terrains as $terrain) {
            if (isset($terrain['id']) && (string) $terrain['id'] === $id) {
                return hajkic_clean_text((string) ($terrain['name'] ?? ''));
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $types
     * @return array{key:string,label:string}
     */
    private function categoryInfo(?string $id, array $types): array
    {
        if ($id !== null && isset($types[$id]) && is_array($types[$id])) {
            return [
                'key'   => (string) ($types[$id]['slug'] ?? 'planinarski-putevi'),
                'label' => hajkic_clean_text((string) ($types[$id]['name'] ?? 'Planinarski i pešački putevi')) ?? 'Planinarski i pešački putevi',
            ];
        }

        return ['key' => 'planinarski-putevi', 'label' => 'Planinarski i pešački putevi'];
    }
}
