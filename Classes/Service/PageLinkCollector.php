<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

/**
 * Extracts the internal links of one rendered page.
 *
 * A sitemap lists pages, not the links on them. Plugin links carrying Extbase
 * arguments never appear there, which is exactly where a plugin that has been
 * moved to another namespace or another page stops working — so those links
 * have to be read out of the rendered HTML.
 */
class PageLinkCollector
{
    /**
     * Absolute and root-relative hrefs. Deliberately tolerant about quoting and
     * attribute order, since this reads real templates rather than generated
     * markup.
     */
    private const string HREF_PATTERN = '/<a\b[^>]*?\shref\s*=\s*(["\'])(.*?)\1/i';

    /**
     * Returns absolute URLs on $host found in $html.
     *
     * @param bool $onlyWithArguments Keep only links whose query string carries
     *                                Extbase plugin arguments (tx_…). Those are
     *                                the ones a sitemap run cannot reach.
     * @return list<string>
     */
    public function collect(string $html, string $pageUrl, bool $onlyWithArguments = true): array
    {
        $host = $this->hostOf($pageUrl);
        if ($host === null) {
            return [];
        }

        $matches = [];
        if (preg_match_all(self::HREF_PATTERN, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $links = [];
        foreach ($matches as $match) {
            $absolute = $this->toAbsolute(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5), $pageUrl, $host);
            if ($absolute === null) {
                continue;
            }
            if ($onlyWithArguments && !$this->hasPluginArguments($absolute)) {
                continue;
            }

            $links[$absolute] = true;
        }

        return array_keys($links);
    }

    /**
     * The shape of a link: path plus the names of its arguments, values dropped.
     *
     * Hundreds of links that differ only in a record uid all exercise the same
     * plugin on the same page. Grouping by shape is what keeps a full crawl to a
     * few hundred requests instead of tens of thousands, without losing a case.
     */
    public function shapeOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $path = $parts['path'] ?? '';

        $query = $parts['query'] ?? '';
        $arguments = [];
        if ($query !== '') {
            parse_str($query, $parsed);
            $arguments = $this->flattenKeys($parsed);
        }
        sort($arguments);

        return $path . '?' . implode('&', $arguments);
    }

    /**
     * The same URL with every query argument removed — the control case for
     * "do these arguments change anything at all?".
     */
    public function withoutArguments(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        return (is_string($scheme) ? $scheme . '://' : '')
            . (is_string($host) ? $host : '')
            . (is_string($path) ? $path : '');
    }

    private function hasPluginArguments(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $query = $parts['query'] ?? null;

        return is_string($query) && str_contains($query, 'tx_');
    }

    private function hostOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $host = $parts['host'] ?? null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Resolves $href against $pageUrl and returns it only if it stays on $host
     * and is an ordinary http(s) page link.
     */
    private function toAbsolute(string $href, string $pageUrl, string $host): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        foreach (['mailto:', 'tel:', 'javascript:', 'data:'] as $scheme) {
            if (stripos($href, $scheme) === 0) {
                return null;
            }
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:' . $href;
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            $absolute = $href;
        } elseif (str_starts_with($href, '/')) {
            $base = parse_url($pageUrl);
            if (!is_array($base)) {
                return null;
            }
            $absolute = ($base['scheme'] ?? 'https') . '://' . $host . $href;
        } else {
            // Relative to the current directory. Rare in this codebase and not
            // worth resolving wrongly, so it is skipped rather than guessed.
            return null;
        }

        if ($this->hostOf($absolute) !== $host) {
            return null;
        }

        return $this->stripFragment($absolute);
    }

    private function stripFragment(string $url): string
    {
        $position = strpos($url, '#');

        return $position === false ? $url : substr($url, 0, $position);
    }

    /**
     * Flattens a parsed query into dotted argument names, so
     * tx_plugin[controller] becomes "tx_plugin.controller".
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private function flattenKeys(array $values, string $prefix = ''): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($value)) {
                foreach ($this->flattenKeys($value, $name) as $nested) {
                    $keys[] = $nested;
                }
                continue;
            }
            $keys[] = $name;
        }

        return $keys;
    }
}
