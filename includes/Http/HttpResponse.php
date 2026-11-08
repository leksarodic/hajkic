<?php

declare(strict_types=1);

namespace Hajkic\Http;

/**
 * Immutable-ish HTTP result used by the importer.
 */
final class HttpResponse
{
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly bool $fromCache = false,
        public readonly bool $notModified = false,
        public readonly ?string $error = null,
        public readonly ?string $finalUrl = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);

        return $this->headers[$name] ?? null;
    }

    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    public function effectiveUrl(): string
    {
        return $this->finalUrl ?? $this->url;
    }
}
