<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

/**
 * Reads the sitemap group — the name of the XML sitemap provider in TYPO3,
 * e.g. "pages" — from the URL of a (sub-)sitemap.
 *
 * TYPO3 v13 links sub-sitemaps as "?sitemap=pages", v14 as
 * "?tx_seo[sitemap]=pages". Both are read, so sitemaps of either version
 * produce the same groups and stay comparable.
 */
class SitemapGroupExtractor
{
    public function extract(string $sitemapUrl): string
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
