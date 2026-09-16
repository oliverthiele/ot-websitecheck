<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

class UrlHostRewriter
{
    /**
     * Replaces the host (and port) of $url with $toHost, leaving scheme, path,
     * query and fragment untouched. Only rewrites $url when its host matches
     * $fromHost exactly; every other URL is returned unchanged, so a mixed
     * sitemap (rare, but not ruled out) does not get silently pointed at the
     * wrong host.
     */
    public function rewrite(string $url, string $fromHost, string $toHost): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host !== $fromHost) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? null;
        $path = $parts['path'] ?? null;
        $query = $parts['query'] ?? null;
        $fragment = $parts['fragment'] ?? null;

        $rewritten = (is_string($scheme) ? $scheme . '://' : '') . $toHost;
        $rewritten .= is_string($path) ? $path : '';
        $rewritten .= is_string($query) ? '?' . $query : '';
        $rewritten .= is_string($fragment) ? '#' . $fragment : '';

        return $rewritten;
    }

    /**
     * Replaces the host of $url with $toHost, whatever host it had before.
     */
    public function replace(string $url, string $toHost): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return $url;
        }

        return $this->rewrite($url, $host, $toHost);
    }
}
