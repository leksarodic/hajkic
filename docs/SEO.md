# SEO and AI discoverability

Hajkić is designed so a person (and a search engine or AI assistant) can find and understand a trail without executing JavaScript.

## Server-rendered pages

- `/` — interactive map (client-side, but rich meta and canonical to the trail page when `?trail=` is used)
- `/staze` — server-rendered catalogue with the first cards plus a complete internal link directory
- `/staza/{slug}` — one complete server-rendered page per trail:
  - unique `<title>`, meta description and canonical
  - visible breadcrumbs and one `<h1>`
  - stats, description, landmarks, marking, club, accommodation, notes, source and dates
  - inline SVG route preview generated from real GPX geometry
  - inline static elevation profile where elevation exists
  - related trails in the same mountain/region
  - Open Graph and Twitter image
  - `TouristTrip` + `BreadcrumbList` JSON-LD
- `/o-projektu` — methodology, official vs calculated values, attribution, disclaimer
- `404.html`

## Machine-readable discovery

- `sitemap.xml` — all static pages and every trail
- `robots.txt` — crawlable, with sitemap and LLM index
- `llms.txt` — concise site description, dataset URLs, sample trails, interpretation rules and citation guidance
- `data/trails-index.json` — lightweight catalogue for search/filter tooling
- `data/overview.geojson` — simplified geometry
- `data/dataset.json` — counts and generation date
- `data/geo/serbia.geojson` — country outline used by the map
- `<link rel="alternate" type="application/json">` on every page
- `Dataset` JSON-LD on catalogue/about pages
- `TouristTrip` JSON-LD on trail pages

## Social images

`og.php?trail={slug}` renders a 1200×630 PNG on demand using GD:
- trail title, mountain/region, distance/difficulty/duration
- real GPX route preview
- source attribution
- cached in `storage/cache/og/`

The static build writes `assets/og/{slug}.png` and `assets/og/default.png` instead.

## Performance

- initial map uses a 124 KB simplified overview, not full GPX
- detailed GeoJSON loads only for the selected trail
- index is a single 0.9 MB JSON (compressed to roughly a fifth by the server)
- search is debounced; cards render in chunks; markers cluster
- Leaflet canvas renderer for hundreds of lines
- assets self-hosted, no third-party scripts
- static build has no PHP runtime

## Content principles that help both people and AI

- one canonical URL per trail
- clear “official PSS” vs “calculated from GPX” language
- null values instead of fake zeros
- explicit dates (`last_checked_at`, PSS update date)
- visible source links and non-affiliation disclaimer
- consistent Serbian Latin terminology
- descriptive alt text and ARIA labels on the map, charts and controls
