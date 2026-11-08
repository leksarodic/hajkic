<?php
$trailCount = (int) (($indexMeta['counts']['trails'] ?? 0));
?>
<main class="error-page__main" id="main">
    <div class="error-mark" aria-hidden="true">404</div>
    <p class="eyebrow">Stranica nije pronađena</p>
    <h1>Ova staza vodi u off-trail.</h1>
    <p>Stranica koju tražite ne postoji, ali <?= h((string) $trailCount) ?> staza vas čeka u katalogu.</p>
    <div class="error-actions">
        <a class="btn btn--primary" href="<?= h(url('/staze')) ?>">Katalog staza <?= hajkic_icon('arrow') ?></a>
        <a class="btn btn--ghost" href="<?= h(url('/')) ?>">Mapa</a>
    </div>
    <p class="error-hint">Ako ste stigli sa starog linka, potražite stazu po nazivu u pretrazi.</p>
</main>
