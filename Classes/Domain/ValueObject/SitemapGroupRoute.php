<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * A route enhancer that puts the sitemap group into the path instead of the
 * query string — e.g. "sitemap-type/{sitemap}" from the EXT:seo site set
 * "typo3/seo-sitemap", which turns "?tx_seo[sitemap]=pages" into
 * "/sitemap-type/pages/sitemap.xml".
 */
final readonly class SitemapGroupRoute
{
    /**
     * @param string $pattern Regular expression with one capture group for the route value.
     * @param array<string, string> $valueMap Route value => sitemap group, from a StaticValueMapper aspect. Empty when the route value is the group itself.
     */
    public function __construct(
        public string $pattern,
        public array $valueMap = [],
    ) {
    }

    /**
     * The sitemap group in $path, or null if the route does not match.
     */
    public function match(string $path): ?string
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }
        $value = rawurldecode($matches[1]);
        if ($this->valueMap === []) {
            return $value;
        }

        // TYPO3 only resolves the values a StaticValueMapper lists, so neither does this.
        return $this->valueMap[$value] ?? null;
    }
}
