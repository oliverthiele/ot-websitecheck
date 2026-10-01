<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\TransferFailure;
use OliverThiele\OtWebsitecheck\Utility\GzipUtility;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

/**
 * Fetches a sitemap and every sitemap it links to, and returns each file as it
 * was received. Failures are kept as documents too: a snapshot has to show that
 * a sub-sitemap was missing, not silently skip it.
 *
 * A gzip-compressed sitemap (sitemap.xml.gz) is decompressed and stored as XML.
 * The crawl stops at MAXIMUM_DEPTH nested indexes and MAXIMUM_DOCUMENTS files;
 * whatever an index lists beyond that is kept as a skipped document.
 */
class SitemapDocumentCrawler
{
    /** The protocol allows no index inside an index; one more level is tolerated. */
    public const int MAXIMUM_DEPTH = 3;
    public const int MAXIMUM_DOCUMENTS = 5000;

    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly SitemapGroupExtractor $sitemapGroupExtractor,
    ) {
    }

    /**
     * @param array<string, mixed> $requestOptions Additional Guzzle request options, e.g. ['auth' => ['user', 'pass']].
     *                                             The credentials are meant for $sitemapUrl: a sitemap the index lists
     *                                             on another host, port or over plain http is requested without them.
     * @return list<SitemapDocument> The given sitemap first, followed by the sitemaps it links to.
     */
    public function crawl(string $sitemapUrl, int $timeout, array $requestOptions = []): array
    {
        $documents = [];
        $visitedUrls = [];
        $this->crawlRecursive($sitemapUrl, '', $sitemapUrl, 0, $timeout, $requestOptions, $documents, $visitedUrls);

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
        string $authorizedUrl,
        int $depth,
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
        if ($depth > self::MAXIMUM_DEPTH || count($documents) >= self::MAXIMUM_DOCUMENTS) {
            $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_SKIPPED, 0, '');
            return;
        }

        $page = $this->pageFetcher->fetch($sitemapUrl, $timeout, UrlUtility::requestOptionsFor($requestOptions, $sitemapUrl, [$authorizedUrl]));
        if ($page->transferFailure === TransferFailure::TooLarge) {
            $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_TOO_LARGE, 0, '');
            return;
        }
        if ($page->isConnectionError()) {
            $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_UNREACHABLE, 0, '');
            return;
        }
        $status = $page->httpStatus;
        $body = $page->body;
        // Served as a file (application/gzip), not with Content-Encoding, which
        // Guzzle would have decoded already.
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = GzipUtility::decode($body, ResponseSizeLimit::MAXIMUM_BYTES);
            // Corrupt, or more than the size limit once decompressed.
            if ($decoded === null) {
                $documents[] = new SitemapDocument($sitemapUrl, $parentUrl, $group, SitemapDocument::TYPE_INVALID, $status, '');
                return;
            }
            $body = $decoded;
        }

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
                $this->crawlRecursive($location, $sitemapUrl, $authorizedUrl, $depth + 1, $timeout, $requestOptions, $documents, $visitedUrls);
            }
        }
    }
}
