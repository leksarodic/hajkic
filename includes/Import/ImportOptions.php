<?php

declare(strict_types=1);

namespace Hajkic\Import;

/**
 * Parsed command-line options for the importer.
 */
final class ImportOptions
{
    public function __construct(
        public readonly ?string $slug = null,
        public readonly int $limit = 0,
        public readonly bool $force = false,
        public readonly bool $refresh = false,
        public readonly bool $dryRun = false,
        public readonly bool $skipGpx = false,
        public readonly bool $incremental = true,
        public readonly bool $includeSitemap = false,
        public readonly bool $prune = false,
        public readonly int $gpxLimit = 0,
        public readonly int $maxPages = 0,
        public readonly ?string $reportPath = null,
        public readonly bool $verbose = false,
        public readonly string $category = 'all',
    ) {
    }

    /**
     * @param list<string> $argv
     */
    public static function fromArgv(array $argv, bool $dataExists = true): self
    {
        $values = [];
        $flags = [
            'force', 'refresh', 'dry-run', 'skip-gpx', 'incremental', 'full',
            'include-sitemap', 'no-sitemap', 'prune', 'verbose', 'help', 'quiet',
        ];

        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                $values[$key] = $value;
            } elseif (in_array($arg, $flags, true)) {
                $values[$arg] = true;
            }
        }

        if (isset($values['help'])) {
            self::printHelp();
            exit(0);
        }

        $full = isset($values['full']);
        $incrementalFlag = isset($values['incremental']);
        $incremental = $incrementalFlag || (!$full && $dataExists);
        if ($full) {
            $incremental = false;
        }

        // Sitemap discovery is opt-in: the register already lists every trail,
        // while the sitemap also contains non-trail profile pages.
        $sitemap = isset($values['include-sitemap']);
        if (isset($values['no-sitemap'])) {
            $sitemap = false;
        }

        return new self(
            slug: isset($values['slug']) ? hajkic_slugify((string) $values['slug'], 90) : null,
            limit: self::intValue($values['limit'] ?? 0),
            force: isset($values['force']),
            refresh: isset($values['refresh']),
            dryRun: isset($values['dry-run']),
            skipGpx: isset($values['skip-gpx']),
            incremental: $incremental,
            includeSitemap: $sitemap,
            prune: isset($values['prune']),
            gpxLimit: self::intValue($values['gpx-limit'] ?? 0),
            maxPages: self::intValue($values['max-pages'] ?? 0),
            reportPath: isset($values['report']) ? (string) $values['report'] : null,
            verbose: isset($values['verbose']),
            category: isset($values['category']) ? (string) $values['category'] : 'all',
        );
    }

    private static function intValue(mixed $value): int
    {
        return max(0, (int) $value);
    }

    public static function printHelp(): void
    {
        $script = $_SERVER['argv'][0] ?? 'scripts/import-pss.php';
        echo <<<TXT
Hajkić — uvoz podataka sa PSS registra

Upotreba:
  php {$script} [opcije]

Opcije:
  --full                Pun uvoz (podrazumevano ako podaci još ne postoje).
  --incremental         Proveri samo promene (podrazumevano kada podaci postoje).
  --limit=N             Obradi najviše N staza (za testiranje).
  --slug=SLUG           Osveži samo jednu stazu.
  --force               Ignoriši keš i ponovo preuzmi stranicu/GPX.
  --refresh             Kao --force, ali zadržava keš za nepromenjene odgovore.
  --dry-run             Analiziraj bez upisa u data/ i storage/.
  --skip-gpx            Ne preuzimaj GPX datoteke (samo metapodaci).
  --gpx-limit=N         Ograniči broj novih GPX preuzimanja u ovom prolazu.
  --max-pages=N         Ograniči broj stranica registra.
  --category=SLUG       Uvezi samo jednu kategoriju (podrazumevano sve staze).
  --include-sitemap     Dodaj i URL-ove iz sitemap-a koji nisu u registru.
  --no-sitemap          Ne koristi sitemap.
  --prune               Ukloni staze koje ne postoje u novom punom uvozu.
  --report=PATH         Sačuvaj JSON izveštaj na zadatu putanju.
  --verbose             Detaljniji ispis.
  --help                Prikaži ovu pomoć.

Bezbednost i pristojnost:
  - poštuje robots.txt i ograničava brzinu zahteva;
  - koristi keš i uslovne zahteve (ETag / Last-Modified);
  - ne poziva se sa javnog web endpointa.

TXT;
    }
}
