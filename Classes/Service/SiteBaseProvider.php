<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SiteBase;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapGroupRoute;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SnapshotEnvironment;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
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

    /**
     * EXT:seo site set whose route enhancers put the sitemap group into the path.
     */
    public const string SEO_SITEMAP_SET = 'typo3/seo-sitemap';

    /**
     * Route arguments that carry the sitemap group: namespaced since TYPO3 v14
     * (#104422), plain before.
     */
    private const array SITEMAP_ROUTE_ARGUMENTS = ['tx_seo/sitemap', 'sitemap'];

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly SetRegistry $setRegistry,
    ) {
    }

    /**
     * @return list<SiteBase>
     */
    public function getBases(): array
    {
        $bases = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $configuration = $site->getConfiguration();
            $sitemapPath = $this->resolveSitemapPath($configuration);
            // Contains the route enhancers of the site sets as well — TYPO3 merges them in.
            $sitemapGroupRoutes = $this->resolveSitemapGroupRoutes($configuration['routeEnhancers'] ?? null);

            // The main base usually is the production one; a variant says what it is in its condition.
            $candidates = [[$configuration['base'] ?? null, SnapshotEnvironment::Live]];
            $baseVariants = $configuration['baseVariants'] ?? null;
            foreach (is_array($baseVariants) ? $baseVariants : [] as $baseVariant) {
                if (!is_array($baseVariant)) {
                    continue;
                }
                $condition = $baseVariant['condition'] ?? '';
                $candidates[] = [
                    $baseVariant['base'] ?? null,
                    is_string($condition) ? SnapshotEnvironment::fromBaseCondition($condition) : null,
                ];
            }

            foreach ($candidates as [$url, $environment]) {
                if (is_string($url) && parse_url($url, PHP_URL_HOST) !== null) {
                    // A URL listed twice keeps what its first entry says.
                    $bases[$url] ??= new SiteBase($site->getIdentifier(), $url, $sitemapPath, $sitemapGroupRoutes, $environment);
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

    /**
     * The routes of the site whose base has the host of $url. A URL outside
     * the configured sites gets the routes of the EXT:seo sitemap set, the
     * TYPO3 default for sites using it.
     *
     * @return list<SitemapGroupRoute>
     */
    public function resolveSitemapGroupRoutesForUrl(string $url): array
    {
        $base = $this->findByHostOf($url);
        if ($base !== null) {
            return $base->sitemapGroupRoutes;
        }

        return $this->resolveSitemapGroupRoutes($this->setRegistry->getSet(self::SEO_SITEMAP_SET)?->routeEnhancers);
    }

    /**
     * What the site configuration says about the environment of $url, if its
     * host belongs to a configured base.
     */
    public function suggestEnvironmentForUrl(string $url): ?SnapshotEnvironment
    {
        return $this->findByHostOf($url)?->environment;
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

    /**
     * Simple route enhancers that map the sitemap argument into the path, like
     * "sitemap-type/{sitemap}" in the EXT:seo sitemap set.
     *
     * @return list<SitemapGroupRoute>
     */
    private function resolveSitemapGroupRoutes(mixed $routeEnhancers): array
    {
        $routes = [];
        foreach (is_array($routeEnhancers) ? $routeEnhancers : [] as $routeEnhancer) {
            if (!is_array($routeEnhancer) || ($routeEnhancer['type'] ?? null) !== 'Simple') {
                continue;
            }
            $routePath = $routeEnhancer['routePath'] ?? null;
            $arguments = $routeEnhancer['_arguments'] ?? null;
            if (!is_string($routePath) || !is_array($arguments)) {
                continue;
            }
            foreach ($arguments as $placeholder => $argument) {
                if (!is_string($placeholder) || !in_array($argument, self::SITEMAP_ROUTE_ARGUMENTS, true)) {
                    continue;
                }
                $pattern = $this->buildRoutePattern($routePath, $placeholder);
                if ($pattern !== null) {
                    $routes[] = new SitemapGroupRoute($pattern, $this->resolveValueMap($routeEnhancer['aspects'] ?? null, $placeholder));
                }
            }
        }

        return $routes;
    }

    /**
     * Turns "sitemap-type/{sitemap}" into a pattern that finds the route
     * anywhere in a path — after a language prefix, before the page type
     * suffix. A route without a static part would match any path segment,
     * so it is not used.
     */
    private function buildRoutePattern(string $routePath, string $placeholder): ?string
    {
        $parts = preg_split('/(\{[^}]+\})/', trim($routePath, '/'), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $pattern = '';
        $hasGroup = false;
        $hasStaticPart = false;
        foreach ($parts as $part) {
            if ($part === '{' . $placeholder . '}') {
                $pattern .= '([^/]+)';
                $hasGroup = true;
            } elseif (str_starts_with($part, '{')) {
                $pattern .= '[^/]+';
            } else {
                $pattern .= preg_quote($part, '#');
                $hasStaticPart = true;
            }
        }

        return $hasGroup && $hasStaticPart ? '#(?:^|/)' . $pattern . '(?:/|$)#' : null;
    }

    /**
     * Route value => sitemap group from a StaticValueMapper aspect, including
     * its localeMap entries.
     *
     * @return array<string, string>
     */
    private function resolveValueMap(mixed $aspects, string $placeholder): array
    {
        $aspect = is_array($aspects) ? ($aspects[$placeholder] ?? null) : null;
        if (!is_array($aspect) || ($aspect['type'] ?? null) !== 'StaticValueMapper') {
            return [];
        }
        $maps = [$aspect['map'] ?? null];
        $localeMaps = $aspect['localeMap'] ?? null;
        foreach (is_array($localeMaps) ? $localeMaps : [] as $localeMap) {
            $maps[] = is_array($localeMap) ? ($localeMap['map'] ?? null) : null;
        }

        $valueMap = [];
        foreach ($maps as $map) {
            foreach (is_array($map) ? $map : [] as $routeValue => $group) {
                if (is_scalar($group)) {
                    $valueMap[(string)$routeValue] = (string)$group;
                }
            }
        }

        return $valueMap;
    }
}
