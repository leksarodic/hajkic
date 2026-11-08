<?php

declare(strict_types=1);

/**
 * Small shared helpers. No framework, no hidden magic.
 */

/**
 * HTML-escape for templates.
 */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Absolute or root-relative URL for an application path.
 */
function url(string $path = ''): string
{
    $base = rtrim((string) config('app.base_url', ''), '/');
    $path = '/' . ltrim($path, '/');

    if ($base === '') {
        return $path;
    }

    return $base . $path;
}

/**
 * Absolute URL for canonical links, Open Graph and the sitemap.
 */
function hajkic_absolute_url(string $path = '/'): string
{
    $configured = rtrim((string) config('app.base_url', ''), '/');
    if ($configured !== '') {
        return $configured . '/' . ltrim($path, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . url($path);
}

/**
 * Versioned asset URL.
 */
function asset(string $path): string
{
    return url($path) . '?v=' . rawurlencode((string) config('app.asset_version', '1'));
}

/**
 * Create a directory if missing.
 */
function hajkic_ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Ne mogu da napravim direktorijum: ' . $dir);
    }
}

/**
 * Encode JSON consistently. Pretty output is for maintainable data files;
 * GeoJSON is written compactly by callers.
 */
function hajkic_json(mixed $value, bool $pretty = true): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }

    return json_encode(hajkic_clean_utf8($value), $flags) . "\n";
}

/**
 * Recursively drop invalid UTF-8 bytes before JSON serialisation.
 * PSS occasionally contains legacy-encoded fragments.
 */
function hajkic_clean_utf8(mixed $value): mixed
{
    if (is_string($value)) {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($clean === false) {
            $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return $clean;
    }

    if (is_array($value)) {
        $result = [];
        foreach ($value as $key => $item) {
            $result[is_string($key) ? (hajkic_clean_utf8($key) ?? $key) : $key] = hajkic_clean_utf8($item);
        }
        return $result;
    }

    return $value;
}

/**
 * Atomically replace a file: write to a temporary sibling, fsync, rename.
 */
function hajkic_write_atomic(string $path, string $contents): void
{
    hajkic_ensure_dir(dirname($path));
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    $handle = @fopen($tmp, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Ne mogu da pišem u: ' . $path);
    }

    try {
        if (fwrite($handle, $contents) === false) {
            throw new RuntimeException('Neuspešan upis: ' . $path);
        }
        fflush($handle);
        if (function_exists('fsync')) {
            @fsync($handle);
        }
    } finally {
        fclose($handle);
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Ne mogu da zamenim fajl: ' . $path);
    }
}

/**
 * Read and decode a JSON file. Returns null when missing or invalid.
 */
function hajkic_read_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($decoded) ? $decoded : null;
}

/**
 * Convert text to a URL/file-safe ASCII slug.
 */
function hajkic_slugify(string $text, int $maxLength = 80): string
{
    $text = trim($text);
    $text = str_replace(['č', 'ć', 'š', 'ž', 'đ', 'Č', 'Ć', 'Š', 'Ž', 'Đ'], ['c', 'c', 's', 'z', 'dj', 'C', 'C', 'S', 'Z', 'Dj'], $text);
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    $text = preg_replace('/-{2,}/', '-', $text) ?? '';

    if ($text === '') {
        $text = 'staza';
    }

    return substr($text, 0, $maxLength);
}

/**
 * Fold Serbian diacritics for accent-insensitive search.
 */
function hajkic_fold(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(
        ['č', 'ć', 'š', 'ž', 'đ', 'Č', 'Ć', 'Š', 'Ž', 'Đ'],
        ['c', 'c', 's', 'z', 'dj', 'c', 'c', 's', 'z', 'dj'],
        $text
    );

    return $text;
}

/**
 * Normalise whitespace and trim.
 */
function hajkic_clean_text(?string $text): ?string
{
    if ($text === null) {
        return null;
    }

    $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    $text = trim($text);

    return $text === '' ? null : $text;
}

/**
 * Convert a small fragment of PSS HTML to readable text.
 * Keeps paragraph/line breaks; strips all markup.
 */
function hajkic_html_to_text(?string $html): ?string
{
    if ($html === null || trim($html) === '') {
        return null;
    }

    $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/(p|li|div|h[1-6])>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<li[^>]*>/i', '• ', $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);

    return hajkic_clean_text($text);
}

/**
 * Parse a Serbian decimal number ("14,5", "1.234,5", "14.5").
 */
function hajkic_parse_number(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '' || preg_match('/^(-+|—+|n\/a|nepoznato)$/iu', $value)) {
        return null;
    }

    // Take the first numeric token, so values like
    // "479/489 i devijacija 831/687" do not collapse into 489831.
    if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $match)) {
        $value = $match[0];
    } else {
        return null;
    }

    $hasComma = str_contains($value, ',');
    $hasDot = str_contains($value, '.');

    if ($hasComma && $hasDot) {
        // Assume the last separator is the decimal mark.
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($hasComma) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float) $value : null;
}

/**
 * Normalise a URL: trim, decode HTML entities, drop fragment and trailing slash
 * only where it is safe. PSS uses trailing slashes for pages.
 */
function hajkic_normalize_url(?string $url, bool $keepTrailingSlash = true): ?string
{
    if ($url === null) {
        return null;
    }

    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return null;
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }

    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '/';
    if ($keepTrailingSlash && !str_ends_with($path, '/') && !str_contains(basename($path), '.')) {
        $path .= '/';
    }
    if (!$keepTrailingSlash && $path !== '/') {
        $path = rtrim($path, '/');
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $path . $query;
}

/**
 * Extract the path slug from a PSS trail URL.
 */
function hajkic_slug_from_url(?string $url): ?string
{
    $url = hajkic_normalize_url($url, false);
    if ($url === null) {
        return null;
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) {
        return null;
    }

    $slug = basename(rtrim($path, '/'));
    if ($slug === '' || $slug === 'terenipp') {
        return null;
    }

    return hajkic_slugify($slug, 90);
}

/**
 * ISO 8601 UTC timestamp for "now".
 */
function hajkic_now(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/**
 * Convert a source date to ISO 8601 UTC when possible.
 */
function hajkic_iso_date(?string $date): ?string
{
    if ($date === null || trim($date) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($date);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Exception) {
        return null;
    }
}

/**
 * Build a stable human-readable date for Serbian UI.
 */
function hajkic_date_sr(?string $iso): ?string
{
    if ($iso === null || trim($iso) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Exception) {
        return null;
    }

    $months = [1 => 'januar', 'februar', 'mart', 'april', 'maj', 'jun', 'jul', 'avgust', 'septembar', 'oktobar', 'novembar', 'decembar'];

    return $dt->format('j') . '. ' . $months[(int) $dt->format('n')] . ' ' . $dt->format('Y') . '.';
}

/**
 * Client IP without trusting arbitrary proxy headers.
 */
function hajkic_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Send a JSON response and stop.
 */
function hajkic_json_response(mixed $data, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo hajkic_json($data);
    exit;
}

/**
 * Redirect and stop.
 */
function hajkic_redirect(string $location, int $status = 302): never
{
    header('Location: ' . $location, true, $status);
    exit;
}

/**
 * Format a float for JSON without locale interference.
 */
function hajkic_round(?float $value, int $precision = 2): ?float
{
    return $value === null ? null : round($value, $precision);
}
