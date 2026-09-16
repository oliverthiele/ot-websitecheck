<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;

/**
 * Fetches a sitemap and every sitemap it links to, and returns each file as it
 * was received. Failures are kept as documents too: a snapshot has to show that
 * a sub-sitemap was missing, not silently skip it.
 */
class SitemapDocumentCrawler
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly SitemapGroupExtractor $sitemapGroupExtractor,
    ) {}

    /**
     * @param array<string, mixed> $requestOptions Additional Guzzle request options, e.g. ['auth' => ['user', 'pass']].
     * @return list<SitemapDocument> The given sitemap first, followed by the sitemaps it links to.
     */
    public function crawl(string $sitemapUrl, int $timeout, array $requestOptions = []): array
    {
        $documents = [];
        $visitedUrls = [];
        $this->crawlRecursive($sitemapUrl, '', $timeout, $requestOptions, $documents, $visitedUrls);

        return $documents;
    }

    /**
     * @param array<string, mixed> $requestOptions
     * @param list<SitemapDocument> $documents
     * @param array<string, bool> $visitedUrls
     */
    private function crawlRecursive(
        string $sitemapUrl,
        string $parentUrl,
        int $timeout,
        array $requestOptions,
        array &$documents,
        array &$visitedUrls,
    ): void {
        if (isset($visitedUrls[$sitemapUrl])) {
            return;
        }
        $visitedUrls[$sitemapUrl] = true;
        $group = $this->sitemapGroupExtractor->extract($sitemapUrl);

        $page = $this->pageFetcher->fetch($sitemapUrl, $timeout, $requestOptions);
        if ($page->isConnectionError()) {
            $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_UNREACHABLE, 0, '');
            return;
        }
        $status = $page->httpStatus;
        $body = $page->body;

        $previousUseErrors = libxml_use_internal_errors(true);
        $xml = $status === 200 ? simplexml_load_string($body) : false;
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if ($xml === false || !in_array($xml->getName(), ['sitemapindex', 'urlset'], true)) {
            $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_INVALID, $status, $body);
            return;
        }

        if ($xml->getName() === 'urlset') {
            $entries = [];
            foreach ($xml->url as $url) {
                $location = trim((string)$url->loc);
                if ($location !== '') {
                    $entries[] = ['url' => $location, 'lastmod' => trim((string)$url->lastmod)];
                }
            }
            $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_URLSET, $status, $body, $entries);
            return;
        }

        $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_INDEX, $status, $body);
        foreach ($xml->sitemap as $sitemap) {
            $location = trim((string)$sitemap->loc);
            if ($location !== '') {
                $this->crawlRecursive($location, $sitemapUrl, $timeout, $requestOptions, $documents, $visitedUrls);
            }
        }
    }
}
