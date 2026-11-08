# Importing the PSS dataset

The importer is a CLI-only tool. There is intentionally **no public web endpoint** that can start a crawl.

## Requirements

- PHP 8.2+ with `curl`, `xml`/`XMLReader`, `mbstring`, `json`, `dom`, `intl` (optional but recommended)
- writable `storage/` and `data/`
- outbound HTTPS access to `pss.rs`

## Commands

```bash
# first import of all configured trail categories
php scripts/import-pss.php --full

# incremental refresh (default once data exists)
php scripts/import-pss.php

# one trail only
php scripts/import-pss.php --slug=staza-br-5-jokina-cuprija-jelje-konjoder --force

# one category (planinarski-putevi, evropski-pesacki-putevi-u-srbiji, planinarske-transverzale)
php scripts/import-pss.php --category=evropski-pesacki-putevi-u-srbiji --force

# smoke test without writing data
php scripts/import-pss.php --limit=5 --skip-gpx --dry-run

# validate the generated dataset
php scripts/validate-data.php

# remove trail files that no longer exist in a complete import
php scripts/import-pss.php --full --prune
```

### Options

| Option | Effect |
|---|---|
| `--full` | process all discovered trails |
| `--incremental` | revalidate pages and only reprocess changes |
| `--limit=N` | process the first N candidates |
| `--slug=SLUG` | force one trail |
| `--category=SLUG` | restrict to one configured category (`all` by default) |
| `--force` | revalidate HTTP cache and reparse/re-write everything |
| `--refresh` | revalidate but reuse unchanged GPX geometry |
| `--dry-run` | no writes to `data/` or `storage/` |
| `--skip-gpx` | metadata only |
| `--gpx-limit=N` | cap new GPX downloads per run |
| `--max-pages=N` | cap register pagination |
| `--include-sitemap` | add sitemap-only URLs (also contains non-trail profile pages) |
| `--no-sitemap` | disable sitemap support |
| `--prune` | delete local trails not present in the new full run (dangerous; explicit) |
| `--report=PATH` | extra JSON report path |

## How discovery works

1. Fetch `https://pss.rs/planinarski-objekti-i-tereni/tereni/?tip=...` for each configured category.
2. Extract the `terrainsObj` JSON embedded by the PSS WordPress theme.
3. Follow `/tereni/page/N/` pagination until the page signature stops changing or no new posts remain.
4. Deduplicate by PSS post ID and normalized permalink.
5. Fetch every individual `/terenipp/{slug}/` page.
6. Respect `robots.txt`, use a 1.1 s page delay, a 0.65 s GPX delay, ETag/Last-Modified caching and bounded retries.

No HTML table scraping is used for metadata: the embedded structured object is more stable. HTML is only used for canonical URL, update date and GPX link cross-checking.

## Incremental updates

- Page cache is revalidated each incremental run.
- If the page is unchanged and the GPX hash is unchanged, calculated geometry is reused without reparsing.
- If a GPX changed, it is reparsed and the GeoJSON/detail file is regenerated.
- Last check and last successful sync dates are stored per trail.
- A lock file prevents overlapping cron runs.

## Scheduled updates

Shared hosting cron example (daily at 04:20):

```cron
20 4 * * * /usr/bin/php /home/USER/domains/example.rs/public_html/scripts/import-pss.php --incremental >> /home/USER/hajkic-cron.log 2>&1
```

Keep the importer outside the public document root if possible, or rely on the included `.htaccess` deny rules. Never expose a URL that triggers it.

## Reports and failures

`data/import-report.json` contains:

- discovered archive posts, sitemap URLs, unique candidates
- processed/created/updated/unchanged/skipped/failed counts
- GPX downloaded/cached/reused/missing/invalid/no-elevation counts
- every warning/error with trail slug and source URL
- `records_needing_attention` and `issue_counts`

A single broken page or GPX never aborts the whole run. Fatal discovery/network errors do.

## Caching

- `storage/cache/http/` — page and GPX responses with checksums, ETag/Last-Modified metadata
- `storage/cache/parsed/` — reserved for derived caches
- `storage/gpx/` — private raw GPX copies for maintainers
- `storage/logs/import.log` — append-only import log

All of `storage/` is denied by `.htaccess` and must be writable by PHP.
