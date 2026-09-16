<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * What the import of one language's sitemap stored.
 */
final readonly class LanguageImportResult
{
    /**
     * @param array<string, int> $urlCountsByGroup sitemap group => number of URLs, sorted by group
     * @param list<SitemapDocument> $failedDocuments
     */
    public function __construct(
        public string $language,
        public string $sitemapUrl,
        public int $fileCount,
        public array $urlCountsByGroup,
        public array $failedDocuments,
    ) {}

    public function getUrlCount(): int
    {
        return array_sum($this->urlCountsByGroup);
    }
}
