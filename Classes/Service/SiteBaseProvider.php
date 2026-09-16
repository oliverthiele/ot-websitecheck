<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SiteBase;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Lists the base URLs of every configured site — "base" and all "baseVariants",
 * regardless of their condition — so one installation can import the sitemaps
 * of each of its environments.
 */
class SiteBaseProvider
{
    /**
     * Page type of the XML sitemap in EXT:seo.
     */
    public const int SITEMAP_PAGE_TYPE = 1533906435;

    public const string DEFAULT_SITEMAP_PATH = 'sitemap.xml';

    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return list<SiteBase>
     */
    public function getBases(): array
    {
        $bases = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configuration = $site->getConfiguration();
            $sitemapPath = $this->resolveSitemapPath($configuration);

            $urls = [$configuration['base'] ?? null];
            $baseVariants = $configuration['baseVariants'] ?? null;
            foreach (is_array($baseVariants) ? $baseVariants : [] as $baseVariant) {
                $urls[] = is_array($baseVariant) ? ($baseVariant['base'] ?? null) : null;
            }

            foreach ($urls as $url) {
                if (is_string($url) && parse_url($url, PHP_URL_HOST) !== null) {
                    $bases[$url] = new SiteBase($site->getIdentifier(), $url, $sitemapPath);
                }
            }
        }

        return array_values($bases);
    }

    public function findByUrl(string $url): ?SiteBase
    {
        foreach ($this->getBases() as $base) {
            if ($base->url === $url) {
                return $base;
            }
        }

        return null;
    }

    /**
     * The sitemap path of the site whose base has the host of $url, or the
     * default for a URL outside the configured sites.
     */
    public function resolveSitemapPathForUrl(string $url): string
    {
        return $this->findByHostOf($url)->sitemapPath ?? self::DEFAULT_SITEMAP_PATH;
    }

    public function isConfiguredHost(string $url): bool
    {
        return $this->findByHostOf($url) !== null;
    }

    private function findByHostOf(string $url): ?SiteBase
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        foreach ($this->getBases() as $base) {
            if ($base->getHost() === strtolower($host)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * The file name a PageType route enhancer maps to the sitemap page type,
     * e.g. "sitemap.xml". Without such a mapping the sitemap is only reachable
     * through the page type argument.
     *
     * @param array<array-key, mixed> $configuration
     */
    private function resolveSitemapPath(array $configuration): string
    {
        $routeEnhancers = $configuration['routeEnhancers'] ?? null;
        foreach (is_array($routeEnhancers) ? $routeEnhancers : [] as $routeEnhancer) {
            if (!is_array($routeEnhancer) || ($routeEnhancer['type'] ?? null) !== 'PageType' || !is_array($routeEnhancer['map'] ?? null)) {
                continue;
            }
            foreach ($routeEnhancer['map'] as $suffix => $pageType) {
                if (is_numeric($pageType) && (int)$pageType === self::SITEMAP_PAGE_TYPE && is_string($suffix) && $suffix !== '') {
                    return $suffix;
                }
            }
        }

        return '?type=' . self::SITEMAP_PAGE_TYPE;
    }
}
