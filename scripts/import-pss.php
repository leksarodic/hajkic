<?php

declare(strict_types=1);

/**
 * Hajkić PSS importer.
 *
 * Usage examples:
 *   php scripts/import-pss.php --full
 *   php scripts/import-pss.php --incremental
 *   php scripts/import-pss.php --slug=staza-br-5-jokina-cuprija-jelje-konjoder
 *   php scripts/import-pss.php --limit=5 --skip-gpx --dry-run
 *
 * This script must only run from the CLI. There is intentionally no public
 * web endpoint that can start a crawl.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$dataExists = is_file(HAJKIC_ROOT . '/data/trails-index.json');
$options = Hajkic\Import\ImportOptions::fromArgv($_SERVER['argv'] ?? [], $dataExists);

// A dry run should not create a lock or write to storage; the importer still
// uses the HTTP cache so repeated dry runs are cheap.
try {
    $importer = new Hajkic\Import\PssImporter();
    $report = $importer->run($options);
    exit(($report['failed'] ?? 0) > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'GREŠKA: ' . $e->getMessage() . PHP_EOL);
    if ((bool) config('app.debug', false)) {
        fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
    }
    exit(1);
}
