<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

/**
 * Reads the sitemap group — the name of the XML sitemap provider in TYPO3,
 * e.g. "pages" — from the URL of a (sub-)sitemap.
 *
 * TYPO3 v13 links sub-sitemaps as "?sitemap=pages", v14 as
 * "?tx_seo[sitemap]=pages". Both are read, so sitemaps of either version
 * produce the same groups and stay comparable. A route enhancer can move the
 * group into the path ("/sitemap-type/pages/sitemap.xml"); those routes come
 * from the site configuration.
 */
class SitemapGroupExtractor
{
    public function __construct(
        private readonly SiteBaseProvider $siteBaseProvider,
    ) {}

    public function extract(string $sitemapUrl): string
    {
        $group = $this->extractFromQuery($sitemapUrl);
        if ($group !== '') {
            return $group;
        }

        $path = parse_url($sitemapUrl, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }
        foreach ($this->siteBaseProvider->resolveSitemapGroupRoutesForUrl($sitemapUrl) as $route) {
            $group = $route->match($path);
            if ($group !== null && $group !== '') {
                return $group;
            }
        }

        return '';
    }

    private function extractFromQuery(string $sitemapUrl): string
    {
        $queryString = parse_url($sitemapUrl, PHP_URL_QUERY);
        if (!is_string($queryString)) {
            return '';
        }
        parse_str($queryString, $queryArguments);

        $group = $queryArguments['sitemap'] ?? null;
        if (is_string($group) && $group !== '') {
            return $group;
        }

        $seoArguments = $queryArguments['tx_seo'] ?? null;
        $group = is_array($seoArguments) ? ($seoArguments['sitemap'] ?? null) : null;

        return is_string($group) ? $group : '';
    }
}
