<?php
/** @var array<string,mixed>|null $indexMeta */
$facets = (array) ($indexMeta['facets'] ?? []);
$counts = (array) ($indexMeta['counts'] ?? []);
$trails = (array) ($indexMeta['trails'] ?? []);
$regions = (array) ($facets['regions'] ?? []);
$mountains = (array) ($facets['mountains'] ?? []);
$difficulties = (array) ($facets['difficulties'] ?? []);
$distanceBuckets = (array) ($facets['distance'] ?? []);
$elevationBuckets = (array) ($facets['elevation'] ?? []);
$categories = (array) ($facets['categories'] ?? []);
$initial = $initial ?? [];
$initialDifficulties = (array) ($initial['difficulty'] ?? []);
$trailCount = (int) ($counts['trails'] ?? 0);
$gpxCount = (int) ($counts['with_gpx'] ?? 0);
$elevationCount = (int) ($counts['with_elevation'] ?? 0);
?>
<main class="catalogue" id="main" data-page="catalogue">
    <section class="catalogue-hero">
        <div class="catalogue-hero__inner">
            <p class="eyebrow">Katalog</p>
            <h1>Sve staze, na jednom mestu.</h1>
            <p class="catalogue-hero__lead">
                <?= h((string) $trailCount) ?> planinarskih i pešačkih puteva iz registra Planinarskog saveza Srbije.
                Pretraži po planini, regionu, težini i dužini — ili otvori mapu i istraži prostor.
            </p>
            <div class="hero-stats">
                <div><strong><?= h((string) $trailCount) ?></strong><span>staza</span></div>
                <div><strong><?= h((string) $gpxCount) ?></strong><span>sa GPX-om</span></div>
                <div><strong><?= h((string) $elevationCount) ?></strong><span>sa visinskim profilom</span></div>
            </div>
            <a class="btn btn--primary catalogue-hero__cta" href="<?= h(url('/')) ?>">
                Otvori mapu <?= hajkic_icon('arrow') ?>
            </a>
        </div>
    </section>

    <section class="catalogue-body">
        <div class="catalogue-toolbar">
            <form class="search search--wide" role="search" id="search-form">
                <label class="sr-only" for="trail-search">Pretraga staza</label>
                <?= hajkic_icon('search', 'search__icon') ?>
                <input id="trail-search" name="q" type="search" placeholder="Naziv, planina, mesto, oznaka, klub…"
                       autocomplete="off" spellcheck="false" value="<?= h((string) ($initial['q'] ?? '')) ?>">
                <button type="button" class="search__clear" id="search-clear" hidden aria-label="Obriši pretragu">
                    <?= hajkic_icon('close') ?>
                </button>
            </form>
            <button type="button" class="filter-btn" id="filter-toggle" aria-expanded="false" aria-controls="filter-panel">
                <?= hajkic_icon('filter') ?>
                <span>Filteri</span>
                <span class="filter-btn__count" id="filter-count" hidden>0</span>
            </button>
            <p class="result-count" id="result-count" aria-live="polite"><?= h((string) $trailCount) ?> staza</p>
        </div>

        <div class="active-filters" id="active-filters"></div>

        <section class="filter-panel filter-panel--inline" id="filter-panel" aria-label="Filteri staza" hidden>
            <div class="filter-panel__head">
                <h2>Filteri</h2>
                <button type="button" class="icon-btn" id="filter-close" aria-label="Zatvori filtere"><?= hajkic_icon('close') ?></button>
            </div>
            <div class="filter-panel__body">
                <fieldset class="filter-group">
                    <legend>Kategorija</legend>
                    <?php if (count($categories) > 1): ?>
                        <select id="filter-category" name="category">
                            <option value="">Sve kategorije</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= h((string) $category['key']) ?>" <?= ($initial['category'] ?? '') === (string) $category['key'] ? 'selected' : '' ?>>
                                    <?= h((string) $category['label']) ?> (<?= (int) $category['count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <label class="check-row">
                            <input type="checkbox" checked disabled>
                            <span><?= h((string) ($categories[0]['label'] ?? 'Planinarski i pešački putevi')) ?></span>
                            <small><?= h((string) ($categories[0]['count'] ?? $trailCount)) ?></small>
                        </label>
                    <?php endif; ?>
                </fieldset>

                <?php if ($regions !== []): ?>
                <fieldset class="filter-group">
                    <legend>Region</legend>
                    <select id="filter-region" name="region">
                        <option value="">Svi regioni</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= h((string) $region['key']) ?>" <?= ($initial['region'] ?? '') === (string) $region['key'] ? 'selected' : '' ?>><?= h((string) $region['label']) ?> (<?= (int) $region['count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </fieldset>
                <?php endif; ?>

                <?php if ($mountains !== []): ?>
                <fieldset class="filter-group">
                    <legend>Planina / predeo</legend>
                    <select id="filter-mountain" name="mountain">
                        <option value="">Sve planine</option>
                        <?php foreach ($mountains as $mountain): ?>
                            <option value="<?= h((string) $mountain['key']) ?>" <?= ($initial['mountain'] ?? '') === (string) $mountain['key'] ? 'selected' : '' ?>><?= h((string) $mountain['label']) ?> (<?= (int) $mountain['count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </fieldset>
                <?php endif; ?>

                <fieldset class="filter-group">
                    <legend>Težina</legend>
                    <div class="chip-grid">
                        <?php foreach ($difficulties as $difficulty): ?>
                            <?php if ((int) $difficulty['count'] === 0) continue; ?>
                            <label class="chip">
                                <input type="checkbox" name="difficulty" value="<?= h((string) $difficulty['key']) ?>" <?= in_array((string) $difficulty['key'], $initialDifficulties, true) ? 'checked' : '' ?>>
                                <span><?= h((string) $difficulty['label']) ?></span><small><?= (int) $difficulty['count'] ?></small>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>Dužina</legend>
                    <select id="filter-distance" name="dist">
                        <option value="">Sve dužine</option>
                        <?php foreach ($distanceBuckets as $bucket): ?>
                            <option value="<?= h((string) $bucket['key']) ?>" <?= ($initial['dist'] ?? '') === (string) $bucket['key'] ? 'selected' : '' ?>><?= h((string) $bucket['label']) ?> (<?= (int) $bucket['count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>Ukupan uspon</legend>
                    <select id="filter-elevation" name="elev">
                        <option value="">Svaki uspon</option>
                        <?php foreach ($elevationBuckets as $bucket): ?>
                            <option value="<?= h((string) $bucket['key']) ?>" <?= ($initial['elev'] ?? '') === (string) $bucket['key'] ? 'selected' : '' ?>><?= h((string) $bucket['label']) ?> (<?= (int) $bucket['count'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>GPX</legend>
                    <div class="segmented">
                        <label><input type="radio" name="gpx" value="" <?= empty($initial['gpx']) ? 'checked' : '' ?>><span>Sve</span></label>
                        <label><input type="radio" name="gpx" value="yes" <?= ($initial['gpx'] ?? '') === 'yes' ? 'checked' : '' ?>><span>Sa GPX-om</span></label>
                        <label><input type="radio" name="gpx" value="no" <?= ($initial['gpx'] ?? '') === 'no' ? 'checked' : '' ?>><span>Bez GPX-a</span></label>
                    </div>
                </fieldset>
            </div>
            <div class="filter-panel__foot">
                <button type="button" class="btn btn--ghost" id="filter-reset">Poništi sve</button>
                <button type="button" class="btn btn--primary" id="filter-apply">Prikaži <span id="filter-apply-count"><?= h((string) $trailCount) ?></span> staza</button>
            </div>
        </section>

        <div class="trail-grid" id="catalogue-grid">
            <?php foreach (array_slice($trails, 0, 12) as $trail): ?>
                <?= hajkic_trail_card((array) $trail, true) ?>
            <?php endforeach; ?>
        </div>

        <div class="list-empty list-empty--catalogue" id="list-empty" hidden>
            <div class="list-empty__mark"><?= hajkic_icon('mountain') ?></div>
            <h2>Nema staza za ove filtere.</h2>
            <p>Probaj širu pretragu ili poništi filtere.</p>
            <button type="button" class="btn btn--primary" id="empty-reset">Poništi filtere</button>
        </div>

        <div class="catalogue-more">
            <button type="button" class="btn btn--ghost" id="load-more" hidden>Prikaži još staza</button>
        </div>

        <?php if ($trails !== []): ?>
            <details class="catalogue-directory">
                <summary>Sve staze A–Ž <span>(<?= h((string) count($trails)) ?>)</span></summary>
                <div class="catalogue-directory__links">
                    <?php foreach ($trails as $item): ?>
                        <a href="<?= h(url('/staza/' . rawurlencode((string) $item['slug']))) ?>"><?= h((string) $item['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </section>
</main>
