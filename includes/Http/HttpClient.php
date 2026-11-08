<?php

declare(strict_types=1);

namespace Hajkic\Http;

use RuntimeException;

/**
 * Polite HTTP client for the PSS importer.
 *
 * Responsibilities:
 *  - host allow-list (no SSRF);
 *  - robots.txt enforcement;
 *  - conservative per-host delays;
 *  - on-disk response cache with ETag/Last-Modified revalidation;
 *  - bounded response size, retries and clear errors.
 */
final class HttpClient
{
    /** @var array<string, float> */
    private static array $lastRequestAt = [];

    /** @var array<string, RobotsPolicy|null> */
    private array $robots = [];

    private string $cacheDir;
    private string $userAgent;
    private int $timeout;
    private int $connectTimeout;
    private int $retries;
    private array $allowedHosts;

    public function __construct(array $config)
    {
        $this->cacheDir = (string) $config['cache_dir'];
        $this->userAgent = (string) $config['user_agent'];
        $this->timeout = (int) $config['timeout_s'];
        $this->connectTimeout = (int) $config['connect_timeout_s'];
        $this->retries = (int) $config['retries'];
        $this->allowedHosts = array_map('strtolower', (array) $config['allowed_hosts']);

        hajkic_ensure_dir($this->cacheDir);
    }

    /**
     * Perform a GET request.
     *
     * Options:
     *  - ttl: int seconds (0 = always revalidate)
     *  - force: bool bypass fresh cache but still use conditional headers
     *  - max_bytes: int
     *  - cache: bool (default true)
     *  - kind: "page"|"gpx"|"robots" (affects delay)
     *  - headers: array<string,string>
     *
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        $url = trim($url);
        $this->assertAllowed($url);

        $kind = (string) ($options['kind'] ?? 'page');
        $delayMs = $kind === 'gpx' ? (int) config('import.gpx_delay_ms', 650) : (int) config('import.delay_ms', 1100);
        if ($kind === 'robots') {
            $delayMs = 250;
        }

        if ($kind !== 'robots') {
            $this->assertRobotsAllowed($url);
        }

        $useCache = ($options['cache'] ?? true) !== false;
        $ttl = array_key_exists('ttl', $options)
            ? (int) $options['ttl']
            : ($kind === 'gpx' ? (int) config('import.gpx_cache_ttl', 2592000) : (int) config('import.page_cache_ttl', 604800));
        $force = (bool) ($options['force'] ?? false);
        $maxBytes = (int) ($options['max_bytes'] ?? ($kind === 'gpx'
            ? config('import.max_gpx_bytes', 25 * 1024 * 1024)
            : config('import.max_page_bytes', 8 * 1024 * 1024)));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];

        $cacheKey = sha1($url);
        $bodyPath = $this->cacheDir . '/' . $cacheKey . '.body';
        $metaPath = $this->cacheDir . '/' . $cacheKey . '.json';
        $meta = hajkic_read_json($metaPath) ?? [];

        $hasCachedBody = $useCache && is_file($bodyPath) && isset($meta['sha256']);
        if ($hasCachedBody) {
            $cachedBody = @file_get_contents($bodyPath);
            if ($cachedBody === false || hash('sha256', $cachedBody) !== $meta['sha256']) {
                $hasCachedBody = false;
                $cachedBody = '';
            }
        } else {
            $cachedBody = '';
        }

        if ($hasCachedBody && !$force && $ttl > 0 && (time() - (int) ($meta['fetched_at'] ?? 0)) < $ttl) {
            return new HttpResponse(
                $url,
                (int) ($meta['status'] ?? 200),
                $cachedBody,
                (array) ($meta['headers'] ?? []),
                true,
                false,
                null,
                $url
            );
        }

        if ($hasCachedBody) {
            if (!empty($meta['etag'])) {
                $headers['If-None-Match'] = (string) $meta['etag'];
            }
            if (!empty($meta['last_modified'])) {
                $headers['If-Modified-Since'] = (string) $meta['last_modified'];
            }
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->retries) {
            $attempt++;
            $this->throttle($url, $delayMs);
            $result = $this->request($url, $headers, $maxBytes);

            if ($result['error'] === null && $result['status'] === 304 && $hasCachedBody) {
                $meta['fetched_at'] = time();
                if ($useCache) {
                    hajkic_write_atomic($metaPath, hajkic_json($meta));
                }

                return new HttpResponse(
                    $url,
                    200,
                    $cachedBody,
                    (array) ($meta['headers'] ?? []),
                    true,
                    true,
                    null,
                    $result['final_url'] ?? $url
                );
            }

            $retryable = $result['error'] !== null
                || in_array($result['status'], [429, 500, 502, 503, 504], true);

            if (!$retryable || $attempt > $this->retries) {
                if ($result['error'] === null && $result['status'] >= 200 && $result['status'] < 300) {
                    if ($useCache) {
                        $this->storeCache($url, $bodyPath, $metaPath, $result, $maxBytes);
                    }
                }

                return new HttpResponse(
                    $url,
                    $result['status'],
                    $result['body'],
                    $result['headers'],
                    false,
                    false,
                    $result['error'],
                    $result['final_url'] ?? $url
                );
            }

            $lastError = $result['error'];
            $sleep = 2 ** $attempt;
            $retryAfter = isset($result['headers']['retry-after']) ? (int) $result['headers']['retry-after'] : 0;
            $sleep = max($sleep, min($retryAfter, 30));
            usleep((int) ($sleep * 1_000_000));
        }

        return new HttpResponse($url, 0, '', [], false, false, $lastError ?? 'Nepoznata greška', $url);
    }

    /**
     * Fetch and evaluate robots.txt for a URL's host.
     */
    private function assertRobotsAllowed(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new HttpException('Neispravan URL: ' . $url);
        }

        $host = strtolower($parts['host']);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!array_key_exists($host, $this->robots)) {
            $robotsUrl = $scheme . '://' . $host . '/robots.txt';
            $this->throttle($robotsUrl, 250);
            $result = $this->request($robotsUrl, [], 1024 * 1024);
            $content = ($result['error'] === null && $result['status'] >= 200 && $result['status'] < 300)
                ? $result['body']
                : null;
            $this->robots[$host] = $content === null ? new RobotsPolicy(null) : new RobotsPolicy($content);
        }

        $policy = $this->robots[$host];
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if ($policy !== null && !$policy->allows($path, $this->userAgent)) {
            throw new HttpException('robots.txt ne dozvoljava: ' . $url);
        }
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new HttpException('Neispravan URL: ' . $url);
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new HttpException('Dozvoljeni su samo HTTP/HTTPS URL-ovi: ' . $url);
        }

        $host = strtolower($parts['host']);
        if (!in_array($host, $this->allowedHosts, true)) {
            throw new HttpException('Host nije na listi dozvoljenih: ' . $host);
        }
    }

    private function throttle(string $url, int $delayMs): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $now = microtime(true);
        $last = self::$lastRequestAt[$host] ?? 0.0;
        $wait = ($delayMs / 1000) - ($now - $last);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        self::$lastRequestAt[$host] = microtime(true);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:string, error:?string, final_url:?string}
     */
    private function request(string $url, array $headers, int $maxBytes): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'PHP cURL ekstenzija nije dostupna.', 'final_url' => $url];
        }

        $responseHeaders = [];
        $body = '';
        $tooLarge = false;
        $effectiveUrl = $url;

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'Ne mogu da inicijalizujem cURL.', 'final_url' => $url];
        }

        $requestHeaders = [
            'Accept: text/html,application/xml,application/gpx+xml,*/*;q=0.8',
            'Accept-Language: sr,en;q=0.7',
            'User-Agent: ' . $this->userAgent,
        ];
        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '') {
                    return $length;
                }
                if (preg_match('~^HTTP/\S+\s+\d{3}~', $trimmed) === 1) {
                    $responseHeaders = [];
                    return $length;
                }
                if (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $curlError = curl_error($ch);

        if ($tooLarge) {
            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => '',
                'error' => 'Odgovor je veći od dozvoljenog limita (' . $maxBytes . ' B).',
                'final_url' => $effectiveUrl,
            ];
        }

        if ($ok === false && $curlError !== '') {
            return ['status' => $status, 'headers' => $responseHeaders, 'body' => '', 'error' => $curlError, 'final_url' => $effectiveUrl];
        }

        // A redirect could leave the allowed host set.
        try {
            $this->assertAllowed($effectiveUrl);
        } catch (HttpException $e) {
            return ['status' => $status, 'headers' => $responseHeaders, 'body' => '', 'error' => $e->getMessage(), 'final_url' => $effectiveUrl];
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body, 'error' => null, 'final_url' => $effectiveUrl];
    }

    private function storeCache(string $url, string $bodyPath, string $metaPath, array $result, int $maxBytes): void
    {
        if (strlen($result['body']) > $maxBytes) {
            return;
        }

        hajkic_write_atomic($bodyPath, $result['body']);
        $meta = [
            'url' => $url,
            'status' => $result['status'],
            'etag' => $result['headers']['etag'] ?? null,
            'last_modified' => $result['headers']['last-modified'] ?? null,
            'content_type' => $result['headers']['content-type'] ?? null,
            'fetched_at' => time(),
            'sha256' => hash('sha256', $result['body']),
            'bytes' => strlen($result['body']),
        ];
        hajkic_write_atomic($metaPath, hajkic_json($meta));
    }
}
