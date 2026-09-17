<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * One base URL of a configured site — the main base or one of its variants —
 * with the path under which the site delivers its XML sitemap.
 */
final readonly class SiteBase
{
    /**
     * @param list<SitemapGroupRoute> $sitemapGroupRoutes Routes that put the sitemap group into the path.
     */
    public function __construct(
        public string $siteIdentifier,
        public string $url,
        public string $sitemapPath,
        public array $sitemapGroupRoutes = [],
    ) {
    }

    public function getHost(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
