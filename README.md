# Hajkić

Interactive, map-first guide to hiking trails in Serbia.

Hajkić combines the public trail register of the **Mountaineering Association of Serbia** (Planinarski savez Srbije — PSS) with real GPX geometry, elevation profiles and practical planning tools. It is deliberately lightweight: PHP + static JSON/GeoJSON + vanilla JavaScript + Leaflet. No Docker, no always-on Node.js server, no paid map API, no API keys and no external AI service.

> **Hajkić is not an official PSS product.** The register can differ from conditions on the ground. GPX accuracy is not guaranteed. Verify conditions independently and hike at your own responsibility.

## What is included

- full-screen interactive Leaflet map of Serbia with trail geometries
- server-rendered catalogue and **one SEO page per trail** (420 pages)
- search and filters by region, mountain, difficulty, distance, ascent and GPX availability
- trail detail panel with official PSS values separated from GPX-calculated values
- interactive elevation profile linked to the map
- “Gde sam?” location lookup with nearest trails, distance and bearing
- experimental **AR pogled** using the rear camera, GPS and phone compass
- configurable raster tile layers, including a Serbian OSM layer where Kosovo and Metohija are shown as part of Serbia
- reusable, polite PSS importer with caching, pagination, duplicate detection, GPX parsing and validation
- static build for GitHub Pages
- `llms.txt`, Dataset/TouristTrip JSON-LD, sitemap, robots.txt and generated OG images

## Stack

| Layer | Choice |
|---|---|
| Server | PHP 8.2+ (tested on 8.5), Apache/shared hosting or PHP built-in server |
| Data | JSON + GeoJSON files under `data/` |
| Map | Leaflet 1.9 + Leaflet.markercluster (vendored) |
| Client | native ES modules, no bundler |
| Tests | plain PHP runner + Node's built-in test runner |
| Deployment | shared PHP hosting or static GitHub Pages build |

## Quick start

```bash
php -S 127.0.0.1:8099 router.php
# open http://127.0.0.1:8099
```

The committed dataset currently contains 420 trails (355 hiking trails, 47 European E4/E7 routes and 18 traversals), 286 with GPX geometry and 245 with a usable elevation profile. To rebuild it from PSS:

```bash
php scripts/import-pss.php --full       # first full import
php scripts/import-pss.php              # incremental refresh
php scripts/validate-data.php
php tests/run.php
npm run test:js
```

## Project structure

```text
index.php                 front controller (map, catalogue, trail pages, about)
router.php                local PHP server router
og.php                    generated 1200×630 social images
llms.txt / llms.php       AI-assistant index
robots.php, sitemap.php   crawl metadata
views/                    server-rendered pages
includes/
  Pss/                    register + trail page parser, paginated discovery
  Geo/                    GPX parser, simplifier, elevation, GeoJSON
  Import/                 importer, locking, reporting
  Data/                   file repository + index/facets builder
  Http/                   polite HTTP client, cache, robots.txt
  Seo/                    OG image renderer
assets/                   CSS, ES modules, fonts, vendored Leaflet
data/
  trails-index.json       lightweight list for map/search
  trails/{slug}.json      full metadata, stats, source links
  geojson/{slug}.geojson  detailed GPX geometry (2D + elevation array)
  overview.geojson        simplified all-trails overview
  geo/serbia.geojson      country outline (Serbia with Kosovo and Metohija)
scripts/                  import, validate, static build, render
tests/                    PHP + JS tests
docs/                     data, import, deployment, testing, SEO notes
```

## Data model in one sentence

Official PSS values (`distance.official_km`, `elevation.official_gain_m`, …) and values calculated from GPX (`distance.calculated_km`, `elevation.calculated_gain_m`, …) are stored and displayed separately. Missing values are `null`, never zero.

## Deployment

- **Shared PHP hosting:** upload the repository (or a release), point the document root at it, make `storage/` writable, set `APP_BASE_URL` if needed. See [docs/DEPLOY.md](docs/DEPLOY.md), [docs/REPORT.md](docs/REPORT.md) and [docs/SEO.md](docs/SEO.md).
- **GitHub Pages:** `php scripts/build-static.php --out=_site` prerenders every page; the included workflow deploys it. See [docs/DEPLOY.md](docs/DEPLOY.md#github-pages).

## Sources and attribution

- Trail data: [Planinarski savez Srbije](https://pss.rs/planinarski-objekti-i-tereni/tereni/?tip=planinarski-putevi)
- Base maps: [OpenStreetMap Srbija](https://openstreetmap.rs/), [OpenTopoMap](https://opentopomap.org), [OpenStreetMap](https://www.openstreetmap.org/copyright)
- Country outline: Natural Earth 1:50m via `@cublya/world-atlas` (public-domain data, UN-style view with Kosovo dissolved into Serbia)
- Leaflet (BSD-2-Clause), Leaflet.markercluster (MIT)
- Fonts: Instrument Serif, Inter Tight and IBM Plex Mono (SIL Open Font License)

Raw PSS GPX files are **not redistributed by default**. Download links point to the original PSS file. See [docs/DATA.md](docs/DATA.md).
