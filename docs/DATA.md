# Data model and source policy

## Source hierarchy

1. **PSS register + individual pages** — primary source for names, codes, regions, mountains, difficulty, official distance/duration/elevation, descriptions, clubs, markings and GPX URLs.
2. **GPX files linked by PSS** — source of route geometry and calculated distance/ascent/descent.
3. **Natural Earth / world-atlas** — country outline only.
4. **OpenStreetMap Serbia / OpenTopoMap** — basemap tiles only.

Nothing is invented. If a field is absent on the PSS page it is stored as `null` and hidden or explicitly marked “nije dostupno” in the UI.

## Official vs calculated

| Field | Meaning |
|---|---|
| `distance.official_km` | value published by PSS |
| `distance.calculated_km` | haversine length of GPX geometry, segment by segment |
| `elevation.official_gain_m` / `loss_m` | value published by PSS |
| `elevation.calculated_gain_m` / `loss_m` | smoothed GPX elevation gain/loss with a noise threshold |
| `elevation.min_m` / `max_m` | calculated from GPX |
| `duration.official_minutes` | PSS estimate |

Calculated values are labelled “iz GPX-a” / “≈” in the UI. They never overwrite official values.

## GPX policy

PSS pages link to GPX files hosted on `pss.rs`. Public availability does **not** automatically mean the files may be redistributed. Hajkić therefore:

- stores downloaded GPX privately in `storage/gpx/` (not web-accessible) for maintenance and reprocessing;
- publishes derived GeoJSON geometry for map rendering;
- links “Preuzmi GPX” to the original PSS URL by default (`import.redistribute_gpx = false`);
- only serves local GPX through `download-gpx.php` if redistribution is explicitly enabled after checking the applicable terms.

Before enabling local GPX redistribution, confirm permission with the source and record it in your deployment notes.

## File formats

### `data/trails-index.json`

Lightweight list used for the initial map and search. Contains one object per trail with:

- `slug`, `name`, `code`, `category`, `region`, `mountain`, `difficulty`
- `distance` (`official_km`, `calculated_km`)
- `duration`, `elevation`, `gpx`
- `start`, `end`, `club`, `center`, `start_point`, `bbox`
- `source` URLs/dates, `quality`, folded `search` string

### `data/trails/{slug}.json`

Full record: metadata, official values, calculated values, GPX status, geometry references, overview line coordinates, data-quality flags and source dates.

### `data/geojson/{slug}.geojson`

`FeatureCollection` with:

- one `LineString` per GPX segment (`properties.kind = "trail"`), 2D coordinates and a parallel `elevations_m` array;
- one `Point` per GPX waypoint (`properties.kind = "waypoint"`).

Segments are never connected across gaps. Detail simplification is 3D so climbs and descents survive on straight stretches.

### `data/overview.geojson`

One simplified `MultiLineString` feature per trail, used for the initial map. Detailed geometry is loaded only when a trail is selected.

## Country outline

`data/geo/serbia.geojson` is derived from Natural Earth 1:50m via `@cublya/world-atlas`, using the UN-style sovereign-state view in which Kosovo is dissolved into Serbia. It is a cartographic representation, not a legal document.

## Duplicate records

The importer deduplicates by PSS post ID and canonical URL. Repeated PSS codes across different records are reported as `duplicate_code` warnings in both the import report and `scripts/validate-data.php`; records are not deleted automatically. Review them before pruning.

## Privacy

No analytics, tracking cookies or advertising. Geolocation and camera are used only after an explicit user action and never sent to a server.
