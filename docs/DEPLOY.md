# Deployment

Hajkić runs on ordinary shared PHP hosting. There is no database, no Docker and no permanent Node.js process.

## Shared hosting (Apache + PHP)

1. Upload the repository so that `index.php`, `assets/`, `data/` and `views/` are in the web root.
2. PHP 8.2+ with `curl`, `xml`, `mbstring`, `json`, `dom`, `gd` (for OG images) and optionally `intl`.
3. Make `storage/` writable by PHP:
   ```bash
   chmod -R 775 storage
   ```
4. Ensure `mod_rewrite`, `mod_deflate` and `mod_expires` are enabled, or map clean URLs manually.
5. If the site is **not** at the domain root, set the base URL:
   ```bash
   APP_BASE_URL="https://example.rs/hajkic"   # or edit config/config.local.php
   ```
   `config/config.local.php` can override any config value:
   ```php
   <?php
   return [
       'app' => ['base_url' => 'https://example.rs/hajkic'],
   ];
   ```
6. Set a contact address for the importer user agent:
   ```bash
   HAJKIC_CONTACT="mail@example.rs"
   ```
   and edit `import.user_agent` in configuration if needed.
7. Run the importer from the command line (see [IMPORT.md](IMPORT.md)).
8. Add a cron job for incremental updates.

### Requirement checklist

- [x] no database
- [x] no Composer install required at runtime
- [x] no API keys
- [x] no build step for production
- [x] tile providers configurable in `config/config.php`
- [x] raw GPX/cache outside the public paths
- [x] `robots.txt` + sitemap
- [x] HTTPS recommended (required for geolocation, camera/AR)
- [x] `.htaccess` denies `config/`, `includes/`, `scripts/`, `storage/`, `tests/`, `docs/`

### Optional nginx

```nginx
root /var/www/hajkic;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.2-fpm.sock; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
location ~ ^/(config|includes|scripts|storage|tests|docs)/ { deny all; }
```

## GitHub Pages (static demo)

GitHub Pages cannot execute PHP. The static build prerenders all pages and keeps the interactive map, search, filters, detail panels and AR working from static JSON. The importer, live `og.php` and PHP-only redirects are not part of the static site.

```bash
HAJKIC_BASE_URL=https://leksarodic.github.io/hajkic \
HAJKIC_STATIC_BUILD=1 \
php scripts/build-static.php --out=_site

# local preview
php -S 127.0.0.1:8100 -t _site
```

The repository includes `.github/workflows/pages.yml`, which builds `_site` and deploys it on every push to `main`. The Pages URL is:

**https://leksarodic.github.io/hajkic/**

Notes:

- The repository must be public on a GitHub Free plan (or Pages must be enabled on a paid plan).
- `HAJKIC_BASE_URL` must include the repository path (`/hajkic`) so assets, data and canonical URLs resolve.
- GPX downloads link directly to PSS (no local PHP redirect).
- AR works because it is entirely client-side and runs on HTTPS.
- OG images are generated into `_site/assets/og/` during the build.

## Security

- no secrets are committed; `.env` and `config/config.local.php` are ignored
- the importer is CLI-only and uses an exclusive lock
- host allow-list + robots.txt + conservative delays
- GPX/cache directories are outside public access
- `download-gpx.php` is disabled by default (redirects to the original PSS file)
- user location and camera never leave the browser

## Updating the dataset in production

```bash
php scripts/import-pss.php --incremental
php scripts/validate-data.php
```

The index/overview are rebuilt automatically at the end of a successful import. No application redeploy is required. For GitHub Pages, push the updated `data/` and let the workflow rebuild.
