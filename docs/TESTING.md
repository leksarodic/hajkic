# Testing

## PHP

```bash
php tests/run.php
php scripts/validate-data.php
```

Covers PSS JSON extraction, metadata mapping, null handling, canonical URLs, GPX URL detection, sitemap parsing, pagination and duplicate detection, GPX parsing (tracks, segments, routes, waypoints, missing elevation, invalid files), distance/ascent calculation, GeoJSON generation, simplification, loop detection, the real local dataset and null-not-zero behaviour.

## JavaScript

```bash
npm run test:js
```

Uses Node's built-in test runner. Covers search normalization, scoring, region/mountain/difficulty/distance/elevation/GPX filters, URL parameter round-trips, active-filter removal and the elevation profile (multi-segment distances, gaps, missing elevation, sampling, nearest-point lookup).

## Browser smoke test

A Playwright smoke test can use the repository root's Playwright install:

```bash
cd /path/to/workspace
NODE_PATH=$PWD/node_modules node hajkic/tests/browser-smoke.cjs
```

Or manually verify:

1. Map loads at 1440×900 and 390×844 without horizontal overflow.
2. Search “Fruška” updates cards and URL.
3. Difficulty/region/GPX filters update the count and map dimming.
4. Clicking a card opens the panel, loads GeoJSON, fits the route and shows the profile.
5. Hovering the profile updates the dot on the map.
6. Mobile: bottom sheet peek → half → full; filter panel covers the viewport; detail panel has a fixed action bar.
7. Direct `/staza/{slug}` loads a server-rendered page with title, stats, description, JSON-LD and related trails.
8. `?trail={slug}` opens the map with that trail selected.
9. `llms.txt`, `sitemap.xml` and `og.php` return the expected content.

## Testing “Gde sam?”

Grant location permission in the browser, then press **Gde sam?** in the sidebar/sheet. The map should:

- zoom to your position with an accuracy circle;
- show a pulsing location marker;
- open “Najbliže staze” with distance and bearing to the start of the nearest trails;
- let you select a trail directly from that list.

If permission is denied, a toast explains why and the map remains usable.

## Testing AR pogled

AR needs **HTTPS** (or `localhost`), a camera, GPS and, for the compass, motion sensors.

### On a phone (recommended)

1. Open the deployed HTTPS site (e.g. the GitHub Pages URL) on the phone.
2. Open the map, select a trail from the list, and tap **AR pogled**.
3. Allow camera, location and motion/compass access.
4. On iPhone, tap **Uključi kompas** if Safari shows the motion-permission button.
5. Point the phone around you. Markers show:
   - the trail start,
   - the nearest route point,
   - nearby GPX waypoints,
   with distance and bearing.
6. Move/rotate; distances and bearings update. Close with the X or the bottom button.

### Local phone testing without a public deploy

- **Android Chrome:** enable USB port forwarding (`chrome://inspect` → Port forwarding) for `localhost:8099`, then open `http://localhost:8099` on the phone. `localhost` is treated as a secure context.
- **iPhone:** use an HTTPS tunnel (Cloudflare Tunnel, ngrok, Tailscale Serve) or deploy to Pages. Plain HTTP on a LAN IP blocks the camera and motion APIs.

### Desktop quick test (fake camera + GPS)

Use Chrome/Chromium with a fake camera and DevTools sensors:

```bash
chrome --use-fake-device-for-media-stream --use-fake-ui-for-media-stream
```

Then:

1. Start the app locally (`php -S 127.0.0.1:8099 router.php`).
2. Open DevTools → **Sensors** → set a custom location near a trail (e.g. 43.66359, 19.70858).
3. Allow camera/geolocation for `http://127.0.0.1:8099`.
4. Select a trail → **AR pogled**. The fake camera pattern should appear, with target markers and distances.
5. Device orientation is often unavailable on desktop; the UI falls back to north-relative placement and says so.

Automated example (Playwright with fake media and geolocation) is in `tests/browser-smoke.cjs`.

### Common AR failure messages

| Message | Cause |
|---|---|
| “Kamera radi samo preko HTTPS-a” | page is not HTTPS and not `localhost` |
| “Pristup kameri je odbijen” | browser camera permission denied |
| “Kompas nije dostupan” | no motion sensor/permission; targets are placed relative to north |
| “Čekam GPS signal…” | no location fix yet; move outdoors or grant location |
| “Pristup lokaciji je odbijen” | geolocation permission denied |

## Static build test

```bash
HAJKIC_BASE_URL=http://127.0.0.1:8100 HAJKIC_STATIC_BUILD=1 php scripts/build-static.php --out=_site
php -S 127.0.0.1:8100 -t _site
```

Verify `/`, `/staze`, `/o-projektu`, `/staza/{slug}/`, `/staza/{slug}`, `/?trail={slug}`, `/sitemap.xml`, `/llms.txt`, `/404.html` and an OG image.
