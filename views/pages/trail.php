<?php
/** @var array<string,mixed> $trail */
/** @var array<string,mixed>|null $geojson */
/** @var list<array<string,mixed>> $related */

$distance = hajkic_format_distance_pair((array) ($trail['distance'] ?? []));
$duration = $trail['duration']['official_text'] ?? hajkic_format_duration_minutes((int) ($trail['duration']['official_minutes'] ?? 0));
$gainOfficial = $trail['elevation']['official_gain_m'] ?? null;
$gainCalculated = $trail['elevation']['calculated_gain_m'] ?? null;
$lossOfficial = $trail['elevation']['official_loss_m'] ?? null;
$lossCalculated = $trail['elevation']['calculated_loss_m'] ?? null;
$gain = $gainOfficial ?? $gainCalculated;
$loss = $lossOfficial ?? $lossCalculated;
$place = hajkic_place_label($trail);
$difficultyKey = (string) ($trail['difficulty']['key'] ?? 'nepoznato');
$difficultyLabel = (string) ($trail['difficulty']['label'] ?? 'Nije navedeno');
$routeSvg = hajkic_route_svg($geojson, $trail['geometry']['bbox'] ?? null);
$profileSvg = hajkic_elevation_svg($geojson);
$gpxUrl = $trail['gpx']['download']['url'] ?? $trail['gpx']['source_url'] ?? null;
$gpxAvailable = (bool) ($trail['gpx']['available'] ?? false);
$sourceUrl = $trail['source']['canonical_url'] ?? $trail['source']['url'] ?? '';
$mapUrl = url('/?trail=' . rawurlencode((string) $trail['slug']));
$updated = hajkic_date_sr($trail['source']['updated_at'] ?? null);
$checked = hajkic_date_sr($trail['source']['last_checked_at'] ?? null);
$description = (string) ($trail['description'] ?? '');
$lead = $description !== '' ? mb_substr(preg_replace('/\s+/u', ' ', $description) ?? $description, 0, 230, 'UTF-8') : '';
if ($lead !== '' && mb_strlen($description, 'UTF-8') > 230) {
    $lead .= '…';
}

$sections = [
    'Opis staze' => $trail['description'] ?? null,
    'Značajni lokaliteti' => $trail['landmarks'] ?? null,
    'Smeštaj i snadbevenost' => $trail['accommodation'] ?? null,
    'Napomene' => $trail['notes'] ?? null,
];
?>
<main class="trail-page" id="main">
    <div class="trail-page__wrap">
        <nav class="breadcrumbs" aria-label="Putanja">
            <a href="<?= h(url('/')) ?>">Hajkić</a>
            <span aria-hidden="true">/</span>
            <a href="<?= h(url('/staze')) ?>">Staze</a>
            <?php if (!empty($trail['mountain']) && $trail['mountain'] !== $trail['name']): ?>
                <span aria-hidden="true">/</span>
                <a href="<?= h(url('/staze?mountain=' . rawurlencode((string) $trail['mountain']))) ?>"><?= h((string) $trail['mountain']) ?></a>
            <?php endif; ?>
            <span aria-hidden="true">/</span>
            <span aria-current="page"><?= h((string) $trail['name']) ?></span>
        </nav>

        <header class="trail-hero">
            <div class="trail-hero__content">
                <p class="eyebrow"><?= h((string) ($trail['category']['label'] ?? 'Planinarska staza')) ?><?= !empty($trail['code']) ? ' · oznaka ' . h((string) $trail['code']) : '' ?></p>
                <h1><?= h((string) $trail['name']) ?></h1>
                <?php if ($place !== ''): ?><p class="trail-hero__place"><?= h($place) ?></p><?php endif; ?>
                <div class="trail-hero__badges">
                    <span class="badge badge--<?= h($difficultyKey) ?>"><?= h($difficultyLabel) ?></span>
                    <?php if ($gpxAvailable): ?><span class="badge badge--laka">GPX dostupan</span><?php else: ?><span class="badge badge--nepoznato">bez GPX-a</span><?php endif; ?>
                    <?php if ($trail['gpx']['has_elevation'] ?? false): ?><span class="badge badge--laka">visinski profil</span><?php endif; ?>
                    <?php if (($trail['data_quality']['level'] ?? '') === 'partial'): ?><span class="detail-quality"><?= hajkic_icon('info') ?> delimični podaci</span><?php endif; ?>
                </div>
                <?php if ($lead !== ''): ?><p class="trail-hero__lead"><?= h($lead) ?></p><?php endif; ?>
                <div class="trail-hero__actions">
                    <a class="btn btn--primary" href="<?= h($mapUrl) ?>"><?= hajkic_icon('pin') ?> Otvori na mapi</a>
                    <?php if ($gpxAvailable && $gpxUrl): ?>
                        <a class="btn btn--ghost" href="<?= h((string) $gpxUrl) ?>" target="_blank" rel="noopener"><?= hajkic_icon('download') ?> Preuzmi GPX</a>
                    <?php endif; ?>
                    <a class="btn btn--ghost" href="<?= h((string) $sourceUrl) ?>" target="_blank" rel="noopener"><?= hajkic_icon('external') ?> PSS stranica</a>
                </div>
                <p class="trail-hero__ar"><?= hajkic_icon('camera') ?> AR pogled i navigacija dostupni su u interaktivnoj mapi.</p>
            </div>

            <div class="trail-hero__visual">
                <?php if ($routeSvg !== null): ?>
                    <div class="route-preview-card">
                        <?= $routeSvg ?>
                        <span class="route-preview-label">GPX geometrija · <?= h((string) ($trail['gpx']['points'] ?? 0)) ?> tačaka</span>
                    </div>
                <?php else: ?>
                    <div class="route-preview-card route-preview-card--empty">
                        <div class="route-preview-empty">
                            <?= hajkic_icon('mountain') ?>
                            <strong>GPX nije dostupan</strong>
                            <span>PSS nije objavio geometriju za ovu stazu.</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <section class="trail-statbar" aria-label="Osnovni podaci o stazi">
            <div class="trail-statbar__cell">
                <span>Dužina</span>
                <strong><?= $distance['text'] !== null ? ($distance['calculated'] ? '≈' : '') . h($distance['text']) . ' <small>km</small>' : '—' ?></strong>
                <em><?= $distance['value'] === null ? 'nije dostupno' : ($distance['calculated'] ? 'izračunato iz GPX-a' : 'zvanično, PSS') ?></em>
            </div>
            <div class="trail-statbar__cell">
                <span>Vreme obilaska</span>
                <strong><?= $duration !== null ? h($duration) : '—' ?></strong>
                <em><?= $duration !== null ? 'procena, PSS' : 'nije dostupno' ?></em>
            </div>
            <div class="trail-statbar__cell">
                <span>Ukupan uspon</span>
                <strong><?= $gain !== null ? h(hajkic_format_number((float) $gain, 0)) . ' <small>m</small>' : '—' ?></strong>
                <em><?= $gain === null ? 'nije dostupno' : ($gainOfficial === null ? 'izračunato iz GPX-a' : 'zvanično, PSS') ?></em>
            </div>
            <div class="trail-statbar__cell">
                <span>Ukupan spust</span>
                <strong><?= $loss !== null ? h(hajkic_format_number((float) $loss, 0)) . ' <small>m</small>' : '—' ?></strong>
                <em><?= $loss === null ? 'nije dostupno' : ($lossOfficial === null ? 'izračunato iz GPX-a' : 'zvanično, PSS') ?></em>
            </div>
        </section>

        <div class="trail-detail-grid">
            <article class="trail-prose">
                <?php foreach ($sections as $title => $text): ?>
                    <?php if (is_string($text) && trim($text) !== ''): ?>
                        <section>
                            <h2><?= h($title) ?></h2>
                            <?php foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $paragraph): ?>
                                <p><?= nl2br(h($paragraph)) ?></p>
                            <?php endforeach; ?>
                        </section>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($profileSvg !== null): ?>
                    <section>
                        <h2>Visinski profil</h2>
                        <p class="trail-prose__note">Izračunato iz GPX geometrije. Zvanični PSS podaci prikazani su odvojeno u zaglavlju.</p>
                        <div class="profile-static-card"><?= $profileSvg ?></div>
                    </section>
                <?php endif; ?>

                <section>
                    <h2>Markacija i uređenost</h2>
                    <dl class="trail-facts">
                        <?php if (!empty($trail['marking'])): ?><div><dt>Vrsta markacije</dt><dd><?= h((string) $trail['marking']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($trail['maintenance'])): ?><div><dt>Uređenost</dt><dd><?= h((string) $trail['maintenance']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($trail['start'])): ?><div><dt>Početak</dt><dd><?= h((string) $trail['start']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($trail['end'])): ?><div><dt>Kraj</dt><dd><?= h((string) $trail['end']) ?></dd></div><?php endif; ?>
                        <?php if (!empty($trail['map_sheet'])): ?><div><dt>Karta</dt><dd><?= h((string) $trail['map_sheet']) ?></dd></div><?php endif; ?>
                    </dl>
                </section>

                <?php if (!empty($trail['club']['name'])): ?>
                    <section>
                        <h2>Klub i kontakt</h2>
                        <p>
                            <?php if (!empty($trail['club']['url'])): ?>
                                <a href="<?= h((string) $trail['club']['url']) ?>" target="_blank" rel="noopener"><?= h((string) $trail['club']['name']) ?></a>
                            <?php else: ?>
                                <?= h((string) $trail['club']['name']) ?>
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($trail['club']['contact'])): ?>
                            <p class="trail-contact"><?= nl2br(h((string) $trail['club']['contact'])) ?></p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if (!empty($trail['additional']) && is_array($trail['additional'])): ?>
                    <section>
                        <h2>Dodatni podaci</h2>
                        <dl class="trail-facts">
                            <?php foreach ($trail['additional'] as $label => $value): ?>
                                <?php if ($value !== null && $value !== '') : ?>
                                    <div><dt><?= h((string) $label) ?></dt><dd><?= nl2br(h((string) $value)) ?></dd></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </dl>
                    </section>
                <?php endif; ?>

                <?php if (!empty($trail['legend']) && is_array($trail['legend'])): ?>
                    <section>
                        <h2>Skraćenice sa PSS stranice</h2>
                        <ul class="trail-legend">
                            <?php foreach ($trail['legend'] as $item): ?><li><?= h((string) $item) ?></li><?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <section class="trail-source-section">
                    <h2>Izvor i provera</h2>
                    <p>
                        Podaci su preuzeti sa <a href="<?= h((string) $sourceUrl) ?>" target="_blank" rel="noopener">stranice staze na sajtu Planinarskog saveza Srbije</a>.
                        <?= $updated !== null ? 'Poslednja izmena na PSS-u: ' . h($updated) . '.' : '' ?>
                        <?= $checked !== null ? 'Hajkić je proverio podatke ' . h($checked) . '.' : '' ?>
                    </p>
                    <p class="trail-source-section__note">
                        Zvanične vrednosti dolaze sa PSS stranice. Vrednosti označene sa „≈“ izračunate su iz GPX geometrije i nisu zvanične.
                        Hajkić nije zvanični proizvod PSS-a. Proverite uslove na terenu pre polaska.
                    </p>
                </section>
            </article>

            <aside class="trail-side">
                <div class="trail-side__card">
                    <h2>Na terenu</h2>
                    <p>Otvori stazu na interaktivnoj mapi, pokreni AR pogled ili preuzmi GPX na telefon.</p>
                    <a class="btn btn--primary btn--block" href="<?= h($mapUrl) ?>"><?= hajkic_icon('pin') ?> Otvori na mapi</a>
                    <?php if ($gpxAvailable && $gpxUrl): ?>
                        <a class="btn btn--ghost btn--block" href="<?= h((string) $gpxUrl) ?>" target="_blank" rel="noopener"><?= hajkic_icon('download') ?> Preuzmi GPX</a>
                    <?php endif; ?>
                    <p class="trail-side__note">AR pogled koristi kameru, GPS i kompas telefona. Radi preko HTTPS-a.</p>
                </div>

                <div class="trail-side__card trail-side__card--source">
                    <h2>Originalni izvor</h2>
                    <p><?= h((string) config('source.name')) ?></p>
                    <a class="btn btn--ghost btn--block" href="<?= h((string) $sourceUrl) ?>" target="_blank" rel="noopener"><?= hajkic_icon('external') ?> PSS stranica</a>
                </div>

                <div class="trail-side__card">
                    <h2>Bezbednost</h2>
                    <p>Registar može odstupati od stanja na terenu. GPX tačnost nije garantovana. Krećite se na sopstvenu odgovornost i proverite vreme, markacije i lokalne uslove.</p>
                    <p><a href="<?= h(url('/o-projektu')) ?>">Više o podacima i odricanju odgovornosti</a></p>
                </div>
            </aside>
        </div>

        <?php if ($related !== []): ?>
            <section class="related-trails" aria-labelledby="related-title">
                <div class="related-trails__head">
                    <div>
                        <p class="eyebrow">Nastavi dalje</p>
                        <h2 id="related-title">Još staza u istom kraju</h2>
                    </div>
                    <a class="btn btn--ghost" href="<?= h(url('/staze' . (!empty($trail['mountain']) ? '?mountain=' . rawurlencode((string) $trail['mountain']) : ''))) ?>">Sve staze <?= hajkic_icon('arrow') ?></a>
                </div>
                <div class="trail-grid">
                    <?php foreach ($related as $item): ?>
                        <?= hajkic_trail_card($item, true) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
