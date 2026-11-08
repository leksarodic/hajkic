<?php

declare(strict_types=1);

namespace Hajkic\Pss;

/**
 * Paginated discovery of PSS trail candidates.
 *
 * The HTTP fetching is injected so pagination and de-duplication can be unit
 * tested without touching the network.
 */
final class PssDiscovery
{
    /** @var callable(int): ?string */
    private $fetchHtml;
    private PssParser $parser;
    /** @var callable(string): void */
    private $logger;

    public function __construct(callable $fetchHtml, ?PssParser $parser = null, ?callable $logger = null)
    {
        $this->fetchHtml = $fetchHtml;
        $this->parser = $parser ?? new PssParser();
        $this->logger = $logger ?? static function (string $message): void {
        };
    }

    public static function archiveUrl(string $base, int $page, string $category): string
    {
        $base = rtrim($base, '/') . '/';
        if ($page <= 1) {
            return $base . '?tip=' . rawurlencode($category);
        }

        return $base . 'page/' . $page . '/?tip=' . rawurlencode($category);
    }

    /**
     * @return array{
     *   candidates: list<array{id:?int,title:?string,url:string,slug:string,source:string}>,
     *   context: array{regions:list<array<string,mixed>>,terrains:list<array<string,mixed>>,types:array<string,mixed>},
     *   pages_fetched:int
     * }
     */
    public function collectArchive(string $category, int $maxPages): array
    {
        $candidates = [];
        $byId = [];
        $byUrl = [];
        $context = ['regions' => [], 'terrains' => [], 'types' => []];
        $previousSignature = null;
        $pages = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $html = ($this->fetchHtml)($page);
            if ($html === null) {
                break;
            }
            $pages++;

            try {
                $parsed = $this->parser->parseRegister($html);
            } catch (\Throwable $e) {
                ($this->logger)(sprintf('Stranica %d nije parsirana: %s', $page, $e->getMessage()));
                break;
            }

            if ($page === 1) {
                $context = [
                    'regions'  => $parsed['regions'],
                    'terrains' => $parsed['terrains'],
                    'types'    => $parsed['types'],
                ];
            }

            $posts = $parsed['posts'];
            $signature = sha1(implode(',', array_map(static fn ($post) => (string) ($post['ID'] ?? ''), $posts)));
            if ($posts === [] || $signature === $previousSignature) {
                break;
            }
            $previousSignature = $signature;

            $newOnPage = 0;
            foreach ($posts as $post) {
                $id = isset($post['ID']) ? (string) $post['ID'] : null;
                $url = hajkic_normalize_url(isset($post['permalink']) ? (string) $post['permalink'] : null);
                if ($url === null) {
                    continue;
                }
                $slug = hajkic_slug_from_url($url);
                if ($slug === null) {
                    continue;
                }

                $key = $id ?? $url;
                if (isset($byId[$key]) || isset($byUrl[$url])) {
                    continue;
                }

                $byId[$key] = true;
                $byUrl[$url] = true;
                $candidates[] = [
                    'id'     => $id !== null ? (int) $id : null,
                    'title'  => isset($post['title']) ? hajkic_clean_text((string) $post['title']) : null,
                    'url'    => $url,
                    'slug'   => $slug,
                    'source' => 'archive',
                ];
                $newOnPage++;
            }

            ($this->logger)(sprintf('Registar, stranica %d: %d zapisa, %d novih.', $page, count($posts), $newOnPage));
            if ($newOnPage === 0) {
                break;
            }
        }

        usort($candidates, static function (array $a, array $b): int {
            $idA = $a['id'] ?? PHP_INT_MAX;
            $idB = $b['id'] ?? PHP_INT_MAX;
            if ($idA === $idB) {
                return strcmp((string) $a['slug'], (string) $b['slug']);
            }
            return $idA <=> $idB;
        });

        return ['candidates' => $candidates, 'context' => $context, 'pages_fetched' => $pages];
    }
}
