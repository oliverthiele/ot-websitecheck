<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;

/**
 * Finds the sitemap of every language a site delivers, from the outside.
 *
 * The start page lists its languages as <link rel="alternate" hreflang="…">.
 * Each of those links points to the language's home page, and the sitemap is
 * expected at a fixed path below it. What counts is what the site actually
 * answers, not a local site configuration — so a language that is active on
 * one environment and not on another shows up exactly where it is active.
 */
class LanguageSitemapDiscovery
{
    private const string DEFAULT_HREFLANG = 'x-default';

    public function __construct(
        private readonly RedirectChainFollower $redirectChainFollower,
    ) {}

    /**
     * @param array<string, mixed> $requestOptions
     * @return array<string, string> hreflang => sitemap URL; empty if the start page lists no languages.
     */
    public function discover(string $startUrl, string $sitemapPath, int $timeout, array $requestOptions = []): array
    {
        $redirectChain = $this->redirectChainFollower->follow($startUrl, $timeout, 10, $requestOptions);
        if ($redirectChain->abortReason !== RedirectChain::ABORT_NONE || $redirectChain->getFinalStatus() !== 200) {
            return [];
        }

        $sitemaps = [];
        foreach ($this->readAlternateLinks($redirectChain->finalBody) as $hreflang => $href) {
            if (strtolower($hreflang) === self::DEFAULT_HREFLANG) {
                continue;
            }
            $languageBase = (string)UriResolver::resolve(new Uri($redirectChain->getFinalUrl()), new Uri($href));
            $sitemaps[$hreflang] = $this->buildSitemapUrl($languageBase, $sitemapPath);
        }

        return $sitemaps;
    }

    /**
     * @param string $sitemapPath A file name below the base ("sitemap.xml") or a query string ("?type=1533906435").
     */
    public function buildSitemapUrl(string $baseUrl, string $sitemapPath): string
    {
        $uri = (new Uri($baseUrl))->withFragment('');
        if (str_starts_with($sitemapPath, '?')) {
            return (string)$uri->withQuery(substr($sitemapPath, 1));
        }
        $path = rtrim($uri->getPath(), '/') . '/' . ltrim($sitemapPath, '/');

        return (string)$uri->withPath($path)->withQuery('');
    }

    /**
     * @return array<string, string> hreflang => href, in document order
     */
    private function readAlternateLinks(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $document = new \DOMDocument();
        $previousUseErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);
        if (!$loaded) {
            return [];
        }

        $links = [];
        foreach ($document->getElementsByTagName('link') as $link) {
            $relations = preg_split('/\s+/', strtolower($link->getAttribute('rel'))) ?: [];
            $hreflang = trim($link->getAttribute('hreflang'));
            $href = trim($link->getAttribute('href'));
            if (in_array('alternate', $relations, true) && $hreflang !== '' && $href !== '' && !isset($links[$hreflang])) {
                $links[$hreflang] = $href;
            }
        }

        return $links;
    }
}
