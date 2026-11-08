# Import report — final dataset snapshot

Generated: **2026-11-08** (Europe/Belgrade)
Dataset: `data/trails-index.json` / `data/import-report.json`
Source: PSS public trail register

## Summary

| Metric | Value |
|---|---:|
| Register pages discovered (3 trail categories) | **420** |
| Unique trail records imported | **420** |
| Complete records | **231** |
| Partial records | **177** |
| Minimal records | **12** |
| Records with GPX geometry | **286** |
| Records with usable elevation | **245** |
| Records without GPX | **128** |
| Invalid GPX files | **6** |
| Fetch/parse failures | **0** |
| Records needing attention | **255** |

### Trails by category

| Category | Count |
|---|---:|
| Planinarski i pešački putevi | 355 |
| Evropski pešački putevi u Srbiji (E4/E7) | 47 |
| Planinarske transverzale | 18 |

## GPX processing

| Status | Count |
|---|---:|
| Downloaded/processed successfully | 286 |
| Valid but without usable elevation | 41 |
| Missing GPX on PSS page | 128 |
| Invalid GPX (linked file is not GPX) | 6 |

Invalid GPX records need source-side attention:

- `e7-1`
- `e7-2`
- `homoljski-planinarski-maraton`
- `kozelj-ledenicki-vrh`
- `marinovac-glogovacki-vrh`
- `mecje-stene`

Their PSS links return an HTML page instead of a GPX file. Hajkić does not fabricate geometry for them.

## Records needing attention

The full list is in `data/import-report.json` (`records_needing_attention`, `issues`). Most are source gaps, not importer failures:

- 134 records with no description on PSS
- 128 records with no GPX on PSS
- 41 GPX files without usable elevation
- 18 records without difficulty
- 12 distance discrepancies > 25%
- 57 elevation discrepancies > 40%
- 5 duplicate PSS codes across different records (review manually)
- 3 records without region/mountain, 1 without code, 2 without distance

Discrepancies are reported, not silently corrected. Official PSS values remain untouched.

## Reproduce

```bash
php scripts/import-pss.php --full
php scripts/validate-data.php
php tests/run.php
npm run test:js
```

Full detail: `data/import-report.json` and `data/trails/{slug}.json`.
