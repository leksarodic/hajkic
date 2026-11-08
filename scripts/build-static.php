<?php

declare(strict_types=1);

/**
 * Build a fully static copy of Hajkić for GitHub Pages.
 *
 * GitHub Pages cannot run PHP, so every route is rendered here and the client
 * side (map, search, filters, detail panels, AR) keeps working from JSON.
 *
 * Usage:
 *   HAJKIC_BASE_URL=https://leksarodic.github.io/hajkic php scripts/build-static.php --out=_site
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $options['out'] = substr($arg, 6);
    }
    if (str_starts_with($arg, '--base=')) {
        $options['base'] = substr($arg, 7);
    }
}

$base = $options['base'] ?? (getenv('HAJKIC_BASE_URL') ?: 'http://localhost:8099');
$out = $options['out'] ?? '_site';
$root = dirname(__DIR__);
$outPath = str_starts_with($out, '/') ? $out : $root . '/' . $out;

if ($outPath === $root || $outPath === '/' || str_contains($outPath, '..')) {
    fwrite(STDERR, "Neispravna izlazna putanja: {$out}\n");
    exit(1);
}

putenv('HAJKIC_STATIC_BUILD=1');
putenv('HAJKIC_BASE_URL=' . rtrim($base, '/'));

require_once $root . '/includes/bootstrap.php';

$repo = new Hajkic\Data\TrailRepository();
$index = hajkic_read_json($repo->indexPath());
if ($index === null) {
    fwrite(STDERR, "Dataset nije pronađen. Pokrenite importer pre build-a.\n");
    exit(1);
}
$trails = (array) ($index['trails'] ?? []);

function rm_tree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            rm_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function copy_tree(string $source, string $target, array $exclude = []): void
{
    if (!is_dir($source)) {
        return;
    }
    hajkic_ensure_dir($target);
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (in_array($entry, $exclude, true)) {
            continue;
        }
        $from = $source . '/' . $entry;
        $to = $target . '/' . $entry;
        if (is_dir($from)) {
            copy_tree($from, $to, $exclude);
        } else {
            hajkic_ensure_dir(dirname($to));
            copy($from, $to);
        }
    }
}

function write_static(string $path, string $contents): void
{
    hajkic_ensure_dir(dirname($path));
    hajkic_write_atomic($path, $contents);
}

function render_route(string $uri): string
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/render-page.php')
        . ' ' . escapeshellarg($uri);
    $lines = [];
    $code = 0;
    exec($command . ' 2>&1', $lines, $code);
    $output = implode("\n", $lines);
    if ($code !== 0) {
        throw new RuntimeException("Render nije uspeo ({$code}) za {$uri}: {$output}");
    }
    if (str_contains($output, 'PHP Fatal error') || str_contains($output, 'PHP Warning')) {
        throw new RuntimeException("PHP greška pri renderu {$uri}: {$output}");
    }
    return $output;
}

echo "Hajkić — statički build\n";
echo "Base URL: " . rtrim($base, '/') . "\n";
echo "Izlaz: {$outPath}\n\n";

if (is_dir($outPath)) {
    rm_tree($outPath);
}
hajkic_ensure_dir($outPath);

echo "Kopiram assets i data…\n";
copy_tree($root . '/assets', $outPath . '/assets', ['og']);
copy_tree($root . '/data', $outPath . '/data', ['import-report.json', '.import-state.json']);

$routes = [
    '/' => 'index.html',
    '/staze' => 'staze/index.html',
    '/o-projektu' => 'o-projektu/index.html',
];

echo "Rendam osnovne stranice…\n";
foreach ($routes as $uri => $file) {
    write_static($outPath . '/' . $file, render_route($uri));
    echo "  {$uri}\n";
}

$count = 0;
foreach ($trails as $trail) {
    $slug = (string) ($trail['slug'] ?? '');
    if ($slug === '') {
        continue;
    }
    write_static(
        $outPath . '/staza/' . $slug . '/index.html',
        render_route('/staza/' . $slug)
    );
    $count++;
    if ($count % 50 === 0) {
        echo "  {$count}/" . count($trails) . " staza…\n";
    }
}
echo "  {$count} stranica staza.\n";

echo "Rendam 404, sitemap, robots i llms…\n";
write_static($outPath . '/404.html', render_route('/404-nije-pronadjeno'));
write_static($outPath . '/sitemap.xml', render_route('/sitemap.xml'));
write_static($outPath . '/robots.txt', render_route('/robots.txt'));
write_static($outPath . '/llms.txt', render_route('/llms.txt'));
write_static($outPath . '/.nojekyll', '');

echo "Generišem OG slike…\n";
if (function_exists('imagecreatetruecolor')) {
    $og = new Hajkic\Seo\OgImage();
    try {
        write_static($outPath . '/assets/og/default.png', $og->render(null));
        $ogCount = 0;
        foreach ($trails as $trail) {
            $slug = (string) ($trail['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $full = $repo->load($slug);
            if ($full === null) {
                continue;
            }
            write_static($outPath . '/assets/og/' . $slug . '.png', $og->render($full));
            $ogCount++;
        }
        echo "  {$ogCount} OG slika.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "OG generisanje preskočeno: " . $e->getMessage() . "\n");
    }
} else {
    echo "  GD nije dostupan; preskačem OG slike.\n";
}

$buildInfo = [
    'built_at' => hajkic_now(),
    'base_url' => rtrim($base, '/'),
    'trails' => count($trails),
    'trail_pages' => $count,
    'source' => config('source.register'),
];
write_static($outPath . '/build.json', hajkic_json($buildInfo, true));

echo "\nGotovo: {$count} stranica staza, " . count($trails) . " zapisa.\n";
