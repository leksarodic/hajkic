<?php

declare(strict_types=1);

namespace Hajkic\Import;

/**
 * Accumulates import statistics, warnings and per-record attention flags.
 */
final class ImportReport
{
    public array $data;
    /** @var array<string, true> */
    private array $attention = [];

    public function __construct(string $mode, string $runAt)
    {
        $this->data = [
            'run_at'       => $runAt,
            'mode'         => $mode,
            'duration_s'   => 0,
            'discovered'   => [
                'archive_posts'       => 0,
                'sitemap_urls'        => 0,
                'sitemap_only'        => 0,
                'unique_candidates'   => 0,
                'not_trails'          => 0,
            ],
            'processed'    => 0,
            'created'      => 0,
            'updated'      => 0,
            'unchanged'    => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'pruned'       => 0,
            'gpx'          => [
                'available'       => 0,
                'downloaded'      => 0,
                'cached'          => 0,
                'reused'          => 0,
                'missing'         => 0,
                'invalid'         => 0,
                'errors'          => 0,
                'no_elevation'    => 0,
                'downloaded_bytes'=> 0,
            ],
            'catalogue'    => [],
            'issues'       => [],
            'records_needing_attention' => [],
        ];
    }

    public function issue(string $level, string $code, string $message, ?string $slug = null, ?string $url = null): void
    {
        $this->data['issues'][] = [
            'level'   => $level,
            'code'    => $code,
            'message' => $message,
            'slug'    => $slug,
            'url'     => $url,
        ];

        if ($slug !== null && $level !== 'info') {
            $this->attention[$slug] = true;
        }
    }

    public function increment(string $key, int $by = 1): void
    {
        $this->data[$key] = (int) ($this->data[$key] ?? 0) + $by;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function finish(): array
    {
        $this->data['records_needing_attention'] = array_keys($this->attention);
        sort($this->data['records_needing_attention']);

        $countsByCode = [];
        foreach ($this->data['issues'] as $issue) {
            $countsByCode[$issue['code']] = ($countsByCode[$issue['code']] ?? 0) + 1;
        }
        $this->data['issue_counts'] = $countsByCode;

        return $this->data;
    }

    public function toJson(): string
    {
        return hajkic_json($this->finish(), true);
    }
}
