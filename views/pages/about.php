<?php
/** @var array<string,mixed>|null $indexMeta */
$counts = (array) ($indexMeta['counts'] ?? []);
$trailCount = (int) ($counts['trails'] ?? 0);
$gpxCount = (int) ($counts['with_gpx'] ?? 0);
$generated = $indexMeta['generated_at'] ?? null;
$lastImport = $indexMeta['last_import'] ?? null;
?>
<main class="about" id="main" data-page="about">
    <section class="about-hero">
        <div class="about-hero__inner">
            <p class="eyebrow">O projektu</p>
            <h1>Mapa koja poštuje stazu.</h1>
            <p class="about-hero__lead">
                Hajkić je nezavisan, pažljivo napravljen vodič kroz planinarske i pešačke staze Srbije.
                Bez reklama, bez praćenja i bez izmišljenih podataka — samo ono što o stazama zaista znamo.
            </p>
            <div class="about-hero__meta">
                <span><?= h((string) $trailCount) ?> staza</span>
                <span><?= h((string) $gpxCount) ?> GPX geometrija</span>
                <?php if ($generated): ?><span>osveženo <?= h(hajkic_date_sr((string) $generated) ?? (string) $generated) ?></span><?php endif; ?>
            </div>
        </div>
    </section>

    <div class="about-body">
        <article class="prose">
            <section id="podaci">
                <h2>Odakle podaci</h2>
                <p>
                    Osnovni izvor je javni registar planinarskih objekata i terena
                    <a href="<?= h((string) config('source.register')) ?>" target="_blank" rel="noopener">Planinarskog saveza Srbije</a>.
                    Naslovi, oznake, regioni, planine, težina, zvanična dužina, vreme obilaska, opis, markacija,
                    klub i ostale napomene preuzimaju se sa pojedinačnih PSS stranica staza.
                </p>
                <p>
                    GPX datoteke se preuzimaju sa istih PSS stranica i koriste isključivo za prikaz stvarne geometrije staze
                    i izračunavanje visinskog profila. Hajkić ne crta prave linije između mesta i ne izmišlja trasu kada GPX ne postoji.
                </p>
            </section>

            <section id="zvanicno">
                <h2>Zvanično i izračunato</h2>
                <p>
                    U aplikaciji su dve vrste brojeva jasno razdvojene. <strong>Zvanične vrednosti</strong> dolaze iz PSS registra.
                    <strong>Izračunate vrednosti</strong> nastaju iz GPX geometrije: dužina se računa haversine formulom po segmentima,
                    a uspon i spust iz visinskih tačaka uz blago izglađivanje i prag šuma.
                </p>
                <div class="about-callout">
                    <p><strong>Oznaka „≈“</strong> u interfejsu znači da vrednost nije zvanična, nego izračunata iz GPX-a.</p>
                    <p>Kada su dostupne obe vrednosti, zvanična se prikazuje prva i nikada se ne zamenjuje izračunatom.</p>
                </div>
            </section>

            <section id="gpx">
                <h2>GPX, tačnost i odgovornost</h2>
                <p>
                    GPX je geografska osnova prikaza, ali nije garancija prohodnosti. Trag može biti snimljen u drugoj sezoni,
                    GPS može imati grešku, a staza može biti premeštena, zarastao ili izgubljen. Visinski podaci u GPX-u
                    često sadrže šum, pa se izračunati uspon može razlikovati od zvaničnog.
                </p>
                <p>
                    PSS izričito napominje da registar može odstupati od stanja na terenu i da se korisnici kreću na sopstvenu odgovornost.
                    Isto važi i za Hajkić. Pre polaska proverite vremensku prognozu, markacije, zatvorene deonice i lokalne uslove.
                </p>
            </section>

            <section id="autorska-prava">
                <h2>Izvori, autorska prava i atribucija</h2>
                <p>
                    Podaci o stazama pripadaju Planinarskom savezu Srbije i njihovim autorima. Hajkić ih prikazuje uz jasan link
                    ka originalnoj stranici svake staze. GPX datoteke se podrazumevano ne redistribuiraju: dugme za preuzimanje
                    vodi na originalni PSS fajl. Ako se utvrdi dozvola za lokalno objavljivanje, to se uključuje isključivo kroz
                    konfiguraciju i uz poštovanje uslova izvora.
                </p>
                <p>
                    Podloge karata su OpenStreetMap Srbija, OpenTopoMap i OpenStreetMap, sa atribucijom na mapi. Podrazumevana
                    podloga prikazuje Kosovo i Metohiju u sastavu Srbije. Hajkić ne koristi plaćene tile servise niti API ključeve.
                    Za ozbiljan saobraćaj preporučuje se sopstveni tile provajder ili keširanje.
                </p>
            </section>

            <section id="privatnost">
                <h2>Privatnost</h2>
                <p>
                    Nema analitike, kolačića za praćenje ni reklamnih mreža. Lokacija se traži samo kada korisnik eksplicitno
                    pritisne dugme „Moja lokacija“ i tada se koristi isključivo u pregledaču. URL parametri služe za deljenje
                    filtera i izabrane staze; ne sadrže lične podatke.
                </p>
            </section>

            <section id="odricanje">
                <h2>Odricanje od odgovornosti</h2>
                <div class="disclaimer-box">
                    <p><strong>Hajkić nije zvanični proizvod Planinarskog saveza Srbije.</strong></p>
                    <p>
                        Informacije mogu biti zastarele ili nepotpune. GPX tačnost nije garantovana. Staze se mogu menjati,
                        a uslovi na terenu mogu biti opasni. Svaka odluka o kretanju po planini je odgovornost korisnika.
                        Ne oslanjajte se isključivo na ovu aplikaciju.
                    </p>
                    <p>
                        U hitnim situacijama pozovite 112. Gorska služba spasavanja Srbije dostupna je na broju 1985.
                    </p>
                </div>
            </section>

            <?php if ($lastImport): ?>
            <section id="dataset">
                <h2>Stanje dataseta</h2>
                <dl class="about-facts">
                    <div><dt>Poslednji uvoz</dt><dd><?= h(hajkic_date_sr((string) ($lastImport['run_at'] ?? '')) ?? '—') ?></dd></div>
                    <div><dt>Režim</dt><dd><?= h((string) ($lastImport['mode'] ?? '—')) ?></dd></div>
                    <div><dt>Broj staza</dt><dd><?= h((string) $trailCount) ?></dd></div>
                    <div><dt>Sa GPX-om</dt><dd><?= h((string) $gpxCount) ?></dd></div>
                </dl>
                <p class="about-facts__note">
                    Dataset se osvežava CLI importerom. Pojedinačne staze mogu imati noviji datum provere od globalnog uvоza.
                </p>
            </section>
            <?php endif; ?>
        </article>

        <aside class="about-aside">
            <div class="about-card">
                <h2>Zašto Hajkić</h2>
                <ul>
                    <li>Stvarna GPX geometrija, bez izmišljenih linija.</li>
                    <li>Zvanično i izračunato nikada se ne mešaju.</li>
                    <li>Nedostajući podaci ostaju prazni — ne nule.</li>
                    <li>Brza mapa, bez učitavanja svih GPX fajlova odjednom.</li>
                </ul>
            </div>
            <div class="about-card about-card--source">
                <h2>Originalni izvor</h2>
                <p>Svaka staza ima link ka svojoj PSS stranici i datum poslednje provere.</p>
                <a class="btn btn--primary" href="<?= h((string) config('source.register')) ?>" target="_blank" rel="noopener">
                    PSS registar <?= hajkic_icon('external') ?>
                </a>
            </div>
        </aside>
    </div>
</main>
