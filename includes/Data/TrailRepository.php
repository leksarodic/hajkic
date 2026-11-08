<?php

declare(strict_types=1);

namespace Hajkic\Data;

/**
 * File-backed trail catalogue. No database, no running service.
 */
final class TrailRepository
{
    private string $dataDir;
    private string $trailsDir;
    private string $geoDir;
    /** @var array<string, array<string,mixed>|null> */
    private array $memory = [];

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? (string) config('paths.data');
        $this->trailsDir = $this->dataDir . '/trails';
        $this->geoDir = $this->dataDir . '/geojson';
        hajkic_ensure_dir($this->trailsDir);
        hajkic_ensure_dir($this->geoDir);
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function trailsDir(): string
    {
        return $this->trailsDir;
    }

    public function geoDir(): string
    {
        return $this->geoDir;
    }

    public function trailPath(string $slug): string
    {
        return $this->trailsDir . '/' . $slug . '.json';
    }

    public function geojsonPath(string $slug): string
    {
        return $this->geoDir . '/' . $slug . '.geojson';
    }

    public function indexPath(): string
    {
        return $this->dataDir . '/trails-index.json';
    }

    public function overviewPath(): string
    {
        return $this->dataDir . '/overview.geojson';
    }

    public function load(string $slug): ?array
    {
        if (array_key_exists($slug, $this->memory)) {
            return $this->memory[$slug];
        }

        $trail = hajkic_read_json($this->trailPath($slug));
        $this->memory[$slug] = $trail;

        return $trail;
    }

    public function save(array $trail): void
    {
        $slug = (string) ($trail['slug'] ?? '');
        if (!self::validSlug($slug)) {
            throw new \InvalidArgumentException('Neispravan slug: ' . $slug);
        }

        hajkic_write_atomic($this->trailPath($slug), hajkic_json($trail, true));
        $this->memory[$slug] = $trail;
    }

    public function delete(string $slug): void
    {
        @unlink($this->trailPath($slug));
        @unlink($this->geojsonPath($slug));
        unset($this->memory[$slug]);
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        $files = glob($this->trailsDir . '/*.json') ?: [];
        $slugs = [];
        foreach ($files as $file) {
            $slug = basename($file, '.json');
            if (self::validSlug($slug)) {
                $slugs[] = $slug;
            }
        }
        sort($slugs);

        return $slugs;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $trails = [];
        foreach ($this->slugs() as $slug) {
            $trail = $this->load($slug);
            if ($trail !== null) {
                $trails[] = $trail;
            }
        }

        return $trails;
    }

    public function writeGeojson(string $slug, array $geojson): void
    {
        if (!self::validSlug($slug)) {
            throw new \InvalidArgumentException('Neispravan slug: ' . $slug);
        }

        $json = json_encode(
            hajkic_clean_utf8($geojson),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
        hajkic_write_atomic($this->geojsonPath($slug), $json . "\n");
    }

    public function loadGeojson(string $slug): ?array
    {
        return hajkic_read_json($this->geojsonPath($slug));
    }

    public function hasGeojson(string $slug): bool
    {
        return is_file($this->geojsonPath($slug));
    }

    public static function validSlug(string $slug): bool
    {
        return $slug !== '' && preg_match('/^[a-z0-9][a-z0-9\-]{0,119}$/', $slug) === 1;
    }
}
