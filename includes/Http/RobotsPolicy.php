<?php

declare(strict_types=1);

namespace Hajkic\Http;

/**
 * Minimal robots.txt evaluator (Google-compatible longest-match semantics).
 */
final class RobotsPolicy
{
    /** @var list<array{agents: list<string>, rules: list<array{allow: bool, path: string}>, crawl_delay: ?float}> */
    private array $groups = [];

    public function __construct(?string $content)
    {
        if ($content === null || trim($content) === '') {
            return;
        }

        $this->parse($content);
    }

    /**
     * Parse the common subset of robots.txt used by PSS and similar sites.
     */
    private function parse(string $content): void
    {
        $agents = [];
        $rules = [];
        $crawlDelay = null;
        $inRules = false;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');
            if ($line === '') {
                if ($agents !== []) {
                    $this->groups[] = ['agents' => $agents, 'rules' => $rules, 'crawl_delay' => $crawlDelay];
                    $agents = [];
                    $rules = [];
                    $crawlDelay = null;
                    $inRules = false;
                }
                continue;
            }

            if (!str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if ($inRules) {
                    $this->groups[] = ['agents' => $agents, 'rules' => $rules, 'crawl_delay' => $crawlDelay];
                    $agents = [];
                    $rules = [];
                    $crawlDelay = null;
                    $inRules = false;
                }
                $agents[] = strtolower($value);
            } elseif ($field === 'allow' || $field === 'disallow') {
                $inRules = true;
                if ($value !== '') {
                    $rules[] = ['allow' => $field === 'allow', 'path' => $value];
                }
            } elseif ($field === 'crawl-delay') {
                $inRules = true;
                $crawlDelay = is_numeric($value) ? (float) $value : null;
            }
        }

        if ($agents !== []) {
            $this->groups[] = ['agents' => $agents, 'rules' => $rules, 'crawl_delay' => $crawlDelay];
        }
    }

    /**
     * Is this path crawlable by the given user agent?
     */
    public function allows(string $path, string $userAgent): bool
    {
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $ua = strtolower($userAgent);
        $matched = null;
        $matchedLength = -1;

        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    continue;
                }
                $token = trim($agent);
                if ($token !== '' && str_contains($ua, $token) && strlen($token) > $matchedLength) {
                    $matched = $group;
                    $matchedLength = strlen($token);
                }
            }
        }

        if ($matched === null) {
            foreach ($this->groups as $group) {
                if (in_array('*', $group['agents'], true)) {
                    $matched = $group;
                    break;
                }
            }
        }

        if ($matched === null || $matched['rules'] === []) {
            return true;
        }

        $bestAllow = null;
        $bestLength = -1;

        foreach ($matched['rules'] as $rule) {
            if (!$this->matches($rule['path'], $path)) {
                continue;
            }

            $length = strlen($rule['path']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $bestAllow = $rule['allow'];
            }
        }

        return $bestAllow ?? true;
    }

    public function crawlDelay(string $userAgent): ?float
    {
        $ua = strtolower($userAgent);

        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ($agent !== '*' && $agent !== '' && str_contains($ua, $agent)) {
                    return $group['crawl_delay'];
                }
            }
        }

        foreach ($this->groups as $group) {
            if (in_array('*', $group['agents'], true)) {
                return $group['crawl_delay'];
            }
        }

        return null;
    }

    private function matches(string $pattern, string $path): bool
    {
        $endAnchor = str_ends_with($pattern, '$');
        if ($endAnchor) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = preg_quote($pattern, '~');
        $regex = str_replace('\*', '.*', $regex);
        $regex = '~^' . $regex . ($endAnchor ? '$' : '') . '~';

        return preg_match($regex, $path) === 1;
    }
}
