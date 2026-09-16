<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
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
    ) {}

    public function resolve(string $url): ?int
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $queryString = parse_url($url, PHP_URL_QUERY);
        $queryString = is_string($queryString) ? $queryString : '';
        parse_str($queryString, $queryParams);

        foreach ($this->siteFinder->getAllSites() as $site) {
            $tail = $this->stripSiteBasePath($path, $site);
            if ($tail === null) {
                continue;
            }

            $requestUri = new Uri('/' . ltrim($tail, '/') . ($queryString !== '' ? '?' . $queryString : ''));

            foreach ($site->getAllLanguages() as $language) {
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

    private function stripSiteBasePath(string $path, Site $site): ?string
    {
        $basePath = rtrim($site->getBase()->getPath(), '/');
        if ($basePath === '') {
            return ltrim($path, '/');
        }
        if (!str_starts_with($path, $basePath)) {
            return null;
        }

        return ltrim(substr($path, strlen($basePath)), '/');
    }
}
