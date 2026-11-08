<?php

declare(strict_types=1);

/**
 * Hajkić bootstrap: autoloading, configuration, paths and shared helpers.
 * Included by every web entry point and every CLI script.
 */

define('HAJKIC_ROOT', dirname(__DIR__));
define('HAJKIC_START', microtime(true));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hajkic\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = HAJKIC_ROOT . '/includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * Merge local configuration over the committed defaults.
 *
 * @param array<string, mixed> $base
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
function hajkic_config_merge(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = hajkic_config_merge($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }

    return $base;
}

$config = require HAJKIC_ROOT . '/config/config.php';
$localFile = HAJKIC_ROOT . '/config/config.local.php';
if (is_file($localFile)) {
    /** @var array<string, mixed> $local */
    $local = require $localFile;
    $config = hajkic_config_merge($config, $local);
}

$GLOBALS['hajkic_config'] = $config;

/**
 * Read a dot-notated configuration value.
 */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['hajkic_config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

date_default_timezone_set((string) config('app.timezone', 'Europe/Belgrade'));
setlocale(LC_ALL, (string) config('app.locale', 'sr_RS.UTF-8'));

if ((bool) config('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require_once HAJKIC_ROOT . '/includes/helpers.php';
require_once HAJKIC_ROOT . '/includes/icons.php';
require_once HAJKIC_ROOT . '/includes/views.php';

// Runtime directories. Kept outside the public data folder where possible.
foreach (['storage', 'storage/cache', 'storage/cache/http', 'storage/cache/parsed', 'storage/logs', 'storage/gpx'] as $dir) {
    hajkic_ensure_dir(HAJKIC_ROOT . '/' . $dir);
}
