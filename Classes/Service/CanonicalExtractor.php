<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

/**
 * Reads the canonical URL a page declares with <link rel="canonical">.
 *
 * EXT:seo renders it on every page: the page itself, the page given in the
 * page property "canonical_link", or — for a page that shows the content of
 * another one — that other page. Nothing has to be added to the checked site.
 */
class CanonicalExtractor
{
    private const string LINK_TAG_PATTERN = '/<link\b[^>]*>/i';

    /**
     * Attribute order and quoting vary between generators, so every attribute
     * is read on its own.
     */
    private const string ATTRIBUTE_PATTERN = '/\b(rel|href)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

    /**
     * @param string $baseUrl the URL the page was delivered under; a relative href is resolved against it
     * @return string the absolute canonical URL, empty when the page declares none
     */
    public function extract(string $html, string $baseUrl): string
    {
        if (preg_match_all(self::LINK_TAG_PATTERN, $html, $tags) === false) {
            return '';
        }
        foreach ($tags[0] as $tag) {
            $attributes = $this->readAttributes($tag);
            $relations = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? ''))) ?: [];
            if (!in_array('canonical', $relations, true)) {
                continue;
            }
            $href = trim(html_entity_decode($attributes['href'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($href === '') {
                continue;
            }

            return $this->resolve($href, $baseUrl);
        }

        return '';
    }

    /**
     * Whether a declared canonical names another URL than the page was
     * delivered under. Compared by path and query only: a staging system often
     * renders the live domain into its canonical, and the checks match rows by
     * path as well.
     */
    public function isElsewhere(string $canonicalUrl, string $pageUrl): bool
    {
        return $canonicalUrl !== '' && UrlUtility::comparablePath($canonicalUrl) !== UrlUtility::comparablePath($pageUrl);
    }

    /**
     * @return array<string, string> lower-case attribute name => raw value
     */
    private function readAttributes(string $tag): array
    {
        if (preg_match_all(self::ATTRIBUTE_PATTERN, $tag, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            // The first occurrence wins, as in a browser.
            $attributes[$name] ??= ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
        }

        return $attributes;
    }

    private function resolve(string $href, string $baseUrl): string
    {
        try {
            $resolved = (string)UriResolver::resolve(new Uri($baseUrl), new Uri($href));
        } catch (MalformedUriException) {
            return '';
        }
        $scheme = strtolower((string)parse_url($resolved, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $resolved : '';
    }
}
