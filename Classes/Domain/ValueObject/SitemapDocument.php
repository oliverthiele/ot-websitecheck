<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * One fetched sitemap file — a sitemap index or a list of URLs — with its raw
 * body, so a stored snapshot can be evaluated again later without the source.
 */
final readonly class SitemapDocument
{
    public const string TYPE_INDEX = 'index';
    public const string TYPE_URLSET = 'urlset';
    public const string TYPE_INVALID = 'invalid';
    public const string TYPE_UNREACHABLE = 'unreachable';

    /**
     * @param list<array{url: string, lastmod: string}> $entries Page URLs of a urlset, empty otherwise.
     */
    public function __construct(
        public string $url,
        public string $parentUrl,
        public string $sitemapGroup,
        public string $type,
        public int $httpStatus,
        public string $body,
        public array $entries = [],
    ) {
    }

    public function isUsable(): bool
    {
        return self::isUsableType($this->type);
    }

    /**
     * Whether a stored document of this type was fetched and parsed.
     */
    public static function isUsableType(string $type): bool
    {
        return $type === self::TYPE_INDEX || $type === self::TYPE_URLSET;
    }
}
