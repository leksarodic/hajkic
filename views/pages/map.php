<?php
/** @var array<string,mixed>|null $indexMeta */
/** @var string|null $selectedTrail */

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
$providers = (array) config('map.providers');
$switchProviders = array_values(array_filter($providers, static fn ($provider) => in_array($provider['id'] ?? '', ['osm-srbija', 'topo'], true)));
if ($switchProviders === []) {
    $switchProviders = array_slice($providers, 0, 2);
}
$defaultProvider = 'osm-srbija';
foreach ($providers as $provider) {
    if (!empty($provider['default'])) {
        $defaultProvider = (string) $provider['id'];
        break;
    }
}
?>
<main class="map-shell" id="main" data-page="map">
    <aside class="explorer" id="explorer" aria-label="Pretraga i lista staza">
        <div class="sheet-grab" id="sheet-grab" role="button" tabindex="0" aria-label="Proširi ili skupi listu staza">
            <span></span>
        </div>

        <div class="explorer__scroll">
            <div class="explorer__head">
                <p class="eyebrow">Planinarske staze Srbije</p>
                <h1 class="explorer__title">Pronađi svoju stazu.</h1>
                <p class="explorer__intro">
                    <?php if ($trailCount > 0): ?>
                        <?= h((string) $trailCount) ?> staza iz PSS registra<?= $gpxCount > 0 ? ', ' . h((string) $gpxCount) . ' sa GPX geometrijom' : '' ?>.
                        Istraži mapu, uporedi visinske profile i ponesi GPX sa sobom.
                    <?php else: ?>
                        Podaci se upravo pripremaju. Osveži stranicu za nekoliko trenutaka.
                    <?php endif; ?>
                </p>

                <form class="search" role="search" id="search-form">
                    <label class="sr-only" for="trail-search">Pretraga staza</label>
                    <?= hajkic_icon('search', 'search__icon') ?>
                    <input id="trail-search" name="q" type="search" placeholder="Naziv, planina, mesto, oznaka…"
                           autocomplete="off" spellcheck="false" value="<?= h((string) ($initial['q'] ?? '')) ?>">
                    <button type="button" class="search__clear" id="search-clear" hidden aria-label="Obriši pretragu">
                        <?= hajkic_icon('close') ?>
                    </button>
                </form>

                <div class="explorer__toolbar">
                    <button type="button" class="filter-btn" id="filter-toggle" aria-expanded="false" aria-controls="filter-panel">
                        <?= hajkic_icon('filter') ?>
                        <span>Filteri</span>
                        <span class="filter-btn__count" id="filter-count" hidden>0</span>
                    </button>
                    <button type="button" class="filter-btn locate-btn" id="locate-nearby" title="Prikaži moju lokaciju i najbliže staze">
                        <?= hajkic_icon('locate') ?>
                        <span>Gde sam?</span>
                    </button>
                    <p class="result-count" id="result-count" aria-live="polite">
                        <?= h((string) $trailCount) ?> staza
                    </p>
                </div>

                <div class="active-filters" id="active-filters" aria-label="Aktivni filteri"></div>

                <section class="nearby-panel" id="nearby-panel" hidden aria-label="Staze u blizini">
                    <div class="nearby-panel__head">
                        <div>
                            <p class="nearby-panel__eyebrow">Vaša lokacija</p>
                            <h2>Najbliže staze</h2>
                        </div>
                        <button type="button" class="icon-btn" id="nearby-close" aria-label="Zatvori najbliže staze"><?= hajkic_icon('close') ?></button>
                    </div>
                    <p class="nearby-panel__meta" id="nearby-meta"></p>
                    <ul class="nearby-list" id="nearby-list"></ul>
                    <p class="nearby-panel__note">Udaljenost je vazdušna linija do početka staze. Proverite pristup i teren pre polaska.</p>
                </section>
            </div>



            <div class="trail-list" id="trail-list" aria-label="Rezultati pretrage">
                <?php foreach (array_slice($trails, 0, 12) as $trail): ?>
                    <?= hajkic_trail_card((array) $trail) ?>
                <?php endforeach; ?>
            </div>

            <div class="list-empty" id="list-empty" hidden>
                <div class="list-empty__mark"><?= hajkic_icon('mountain') ?></div>
                <h2>Nema staza za ove filtere.</h2>
                <p>Probaj širu pretragu, drugi region ili poništi filtere.</p>
                <button type="button" class="btn btn--primary" id="empty-reset">Poništi filtere</button>
            </div>

            <div class="list-sentinel" id="list-sentinel" aria-hidden="true"></div>

            <div class="explorer__foot">
                <p>
                    Podaci: <a href="<?= h((string) config('source.register')) ?>" target="_blank" rel="noopener">Planinarski savez Srbije</a>.
                    Hajkić nije zvanični PSS proizvod.
                </p>
                <p class="explorer__foot-small">Stanje na terenu se menja. Proverite markacije i uslove pre polaska. GPX tačnost nije garantovana.</p>
            </div>
        </div>
    </aside>

            <section class="filter-panel" id="filter-panel" aria-label="Filteri staza" hidden>
                <div class="filter-panel__head">
                    <h2>Filteri</h2>
                    <button type="button" class="icon-btn" id="filter-close" aria-label="Zatvori filtere">
                        <?= hajkic_icon('close') ?>
                    </button>
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
                                <option value="<?= h((string) $region['key']) ?>" <?= ($initial['region'] ?? '') === (string) $region['key'] ? 'selected' : '' ?>>
                                    <?= h((string) $region['label']) ?> (<?= (int) $region['count'] ?>)
                                </option>
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
                                <option value="<?= h((string) $mountain['key']) ?>" <?= ($initial['mountain'] ?? '') === (string) $mountain['key'] ? 'selected' : '' ?>>
                                    <?= h((string) $mountain['label']) ?> (<?= (int) $mountain['count'] ?>)
                                </option>
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
                                    <input type="checkbox" name="difficulty" value="<?= h((string) $difficulty['key']) ?>"
                                        <?= in_array((string) $difficulty['key'], $initialDifficulties, true) ? 'checked' : '' ?>>
                                    <span><?= h((string) $difficulty['label']) ?></span>
                                    <small><?= (int) $difficulty['count'] ?></small>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <fieldset class="filter-group">
                        <legend>Dužina</legend>
                        <select id="filter-distance" name="dist">
                            <option value="">Sve dužine</option>
                            <?php foreach ($distanceBuckets as $bucket): ?>
                                <option value="<?= h((string) $bucket['key']) ?>" <?= ($initial['dist'] ?? '') === (string) $bucket['key'] ? 'selected' : '' ?>>
                                    <?= h((string) $bucket['label']) ?> (<?= (int) $bucket['count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </fieldset>

                    <fieldset class="filter-group">
                        <legend>Ukupan uspon</legend>
                        <select id="filter-elevation" name="elev">
                            <option value="">Svaki uspon</option>
                            <?php foreach ($elevationBuckets as $bucket): ?>
                                <option value="<?= h((string) $bucket['key']) ?>" <?= ($initial['elev'] ?? '') === (string) $bucket['key'] ? 'selected' : '' ?>>
                                    <?= h((string) $bucket['label']) ?> (<?= (int) $bucket['count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </fieldset>

                    <fieldset class="filter-group">
                        <legend>GPX</legend>
                        <div class="segmented" id="filter-gpx">
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

    <section class="map-stage" aria-label="Interaktivna mapa planinarskih staza">
        <div id="map" class="map" role="application" aria-label="Mapa staza Srbije"></div>

        <div class="basemap-switch" id="basemap-switch" role="group" aria-label="Izbor podloge mape">
            <?php foreach ($switchProviders as $provider): ?>
                <button type="button" data-basemap="<?= h((string) $provider['id']) ?>"
                        class="<?= $provider['id'] === $defaultProvider ? 'is-active' : '' ?>"
                        aria-pressed="<?= $provider['id'] === $defaultProvider ? 'true' : 'false' ?>">
                    <?= h($provider['id'] === 'topo' ? 'Topo' : 'Ulična') ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="mobile-search">
            <form role="search" class="mobile-search__form" id="mobile-search-form">
                <label class="sr-only" for="trail-search-mobile">Pretraga staza</label>
                <?= hajkic_icon('search', 'search__icon') ?>
                <input id="trail-search-mobile" type="search" placeholder="Traži stazu, planinu, mesto…"
                       autocomplete="off" spellcheck="false" value="<?= h((string) ($initial['q'] ?? '')) ?>">
            </form>
            <button type="button" class="mobile-filter-btn" id="mobile-filter-btn" aria-label="Filteri" aria-controls="filter-panel" aria-expanded="false">
                <?= hajkic_icon('filter') ?>
                <span id="mobile-filter-count" hidden>0</span>
            </button>
        </div>

        <div class="map-status" id="map-status" role="status" aria-live="polite" hidden>
            <span class="spinner" aria-hidden="true"></span>
            <span id="map-status-text">Učitavanje mape…</span>
        </div>

        <div class="map-controls" aria-label="Kontrole mape">
            <div class="map-controls__group">
                <button type="button" class="map-control" id="zoom-in" aria-label="Uvećaj mapu" title="Uvećaj"><?= hajkic_icon('plus') ?></button>
                <button type="button" class="map-control" id="zoom-out" aria-label="Umanji mapu" title="Umanji"><?= hajkic_icon('minus') ?></button>
            </div>
            <div class="map-controls__group">
                <button type="button" class="map-control" id="map-locate" aria-label="Prikaži moju lokaciju" title="Moja lokacija"><?= hajkic_icon('locate') ?></button>
                <button type="button" class="map-control" id="map-layers" aria-label="Izaberi podlogu mape" title="Podloga mape" aria-expanded="false" aria-controls="layer-panel"><?= hajkic_icon('layers') ?></button>
            </div>
            <div class="map-controls__group">
                <button type="button" class="map-control" id="map-reset" aria-label="Vrati početni pregled" title="Početni pregled"><?= hajkic_icon('reset') ?></button>
                <button type="button" class="map-control" id="map-clear" aria-label="Ukloni selekciju" title="Ukloni selekciju" hidden><?= hajkic_icon('close') ?></button>
            </div>
        </div>

        <div class="layer-panel" id="layer-panel" hidden aria-label="Podloga mape">
            <h2>Podloga mape</h2>
            <div id="layer-options"></div>
            <p class="layer-panel__note">OpenStreetMap i OpenTopoMap su javni servisi. Za veći saobraćaj postavite sopstveni tile provajder.</p>
        </div>

        <div class="map-legend" id="map-legend">
            <button type="button" class="map-legend__toggle" id="legend-toggle" aria-expanded="true" aria-controls="legend-body">
                <span>Legenda</span>
                <?= hajkic_icon('chevron') ?>
            </button>
            <div class="map-legend__body" id="legend-body">
                <span class="legend-item"><i class="legend-line"></i> Planinarska staza</span>
                <span class="legend-item"><i class="legend-line legend-line--selected"></i> Izabrana staza</span>
                <span class="legend-item"><i class="legend-dot legend-dot--start"></i> Početak</span>
                <span class="legend-item"><i class="legend-dot legend-dot--end"></i> Kraj</span>
                <span class="legend-item"><i class="legend-waypoint"></i> Tačka na GPX-u</span>
                <span class="legend-item"><i class="legend-cluster">12</i> Grupa staza</span>
            </div>
        </div>

        <div class="map-attribution">
            Podaci: <a href="<?= h((string) config('source.register')) ?>" target="_blank" rel="noopener">PSS</a>
            <span aria-hidden="true">·</span>
            <a href="<?= h(url('/o-projektu')) ?>">o podacima</a>
        </div>
    </section>

    <aside class="trail-panel" id="trail-panel" hidden aria-label="Detalji staze">
        <div class="trail-panel__scroll" id="trail-panel-content"></div>
        <div class="trail-panel__actions" id="trail-panel-actions"></div>
    </aside>

    <div class="sheet-backdrop" id="sheet-backdrop" hidden></div>
    <div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
</main>

<noscript>
    <div class="noscript-note">
        <strong>Mapa zahteva JavaScript.</strong> Možete pregledati <a href="<?= h(url('/staze')) ?>">katalog staza</a> i otvoriti pojedinačne staze.
    </div>
</noscript>
