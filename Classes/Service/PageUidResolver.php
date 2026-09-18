<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves the TYPO3 page uid a checked URL renders on, so results for the
 * same detail page (one detail page rendering many different records) can be
 * grouped across environments and runs.
 *
 * Route matching in TYPO3 only depends on the request path, not the host
 * (see PageRouter's own docblock: "does not restrict ... or is bound to any
 * domain constraints, as the SiteMatcher has done that already") - which is
 * exactly what makes this work uniformly for a DDEV/staging/live URL alike,
 * without needing SiteMatcher's host-based site lookup at all (that lookup
 * would fail here anyway: a site's base is resolved to a single host per the
 * currently active environment/context, so a foreign host such as a staging
 * or live URL checked from a DDEV context would never match it).
 */
class PageUidResolver
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function resolve(string $url): ?int
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $queryString = parse_url($url, PHP_URL_QUERY);
        $queryString = is_string($queryString) ? $queryString : '';
        parse_str($queryString, $queryParams);

        foreach ($this->siteFinder->getAllSites() as $site) {
            if ($this->stripBasePath($path, $site->getBase()) === null) {
                continue;
            }

            // The router expects the path below the language base, as SiteMatcher
            // hands it over: "/de/imprint" is routed as "imprint" in the German
            // language, never as "de/imprint". Longer bases go first, so "/de/…"
            // is not claimed by a default language at "/".
            foreach ($this->sortByBasePathLength($site->getAllLanguages()) as $language) {
                $tail = $this->stripBasePath($path, $language->getBase());
                if ($tail === null) {
                    continue;
                }

                $requestUri = new Uri('/' . $tail . ($queryString !== '' ? '?' . $queryString : ''));
                $request = (new ServerRequest($requestUri, 'GET'))->withQueryParams($queryParams);
                $siteRouteResult = new SiteRouteResult($requestUri, $site, $language, $tail);

                try {
                    $result = $site->getRouter()->matchRequest($request, $siteRouteResult);
                } catch (\Throwable) {
                    continue;
                }

                if ($result instanceof PageArguments) {
                    return $result->getPageId();
                }
            }
        }

        return null;
    }

    /**
     * @param SiteLanguage[] $languages
     * @return SiteLanguage[]
     */
    private function sortByBasePathLength(array $languages): array
    {
        usort(
            $languages,
            static fn(SiteLanguage $first, SiteLanguage $second): int
                => strlen($second->getBase()->getPath()) <=> strlen($first->getBase()->getPath()),
        );

        return $languages;
    }

    /**
     * A base only matches at a segment boundary: "/de" covers "/de" and "/de/…",
     * but not "/details".
     */
    private function stripBasePath(string $path, UriInterface $base): ?string
    {
        $basePath = rtrim($base->getPath(), '/');
        if ($basePath === '') {
            return ltrim($path, '/');
        }
        if ($path !== $basePath && !str_starts_with($path, $basePath . '/')) {
            return null;
        }

        return ltrim(substr($path, strlen($basePath)), '/');
    }
}
