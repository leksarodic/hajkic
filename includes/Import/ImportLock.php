<?php

declare(strict_types=1);

namespace Hajkic\Import;

/**
 * Simple exclusive lock so two cron runs cannot overlap.
 */
final class ImportLock
{
    /** @var resource|null */
    private $handle = null;
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (HAJKIC_ROOT . '/storage/import.lock');
    }

    public function acquire(): void
    {
        hajkic_ensure_dir(dirname($this->path));
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Ne mogu da otvorim lock fajl.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Uvoz je već pokrenut (lock: ' . $this->path . ').');
        }

        ftruncate($handle, 0);
        fwrite($handle, hajkic_json([
            'pid'        => getmypid(),
            'started_at' => hajkic_now(),
            'host'       => gethostname() ?: 'unknown',
        ]));
        fflush($handle);
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        @unlink($this->path);
    }

    public function __destruct()
    {
        $this->release();
    }
}
