<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\LanguageImportResult;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Exception\SitemapImportException;

/**
 * The one way a sitemap snapshot is created, used by the CLI command and the
 * backend module alike.
 *
 * An import runs in three steps — start, one step per language, finish — so
 * the backend can import language by language in separate requests without
 * running into request timeouts. Each language is fetched completely before it
 * is stored. A snapshot whose import was interrupted keeps the status
 * "importing" and is shown as incomplete, never as a complete snapshot.
 */
class SitemapSnapshotImporter
{
    public const int MAXIMUM_LABEL_LENGTH = 100;

    public function __construct(
        private readonly LanguageSitemapDiscovery $languageSitemapDiscovery,
        private readonly SitemapDocumentCrawler $sitemapDocumentCrawler,
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $requestOptions
     * @return array<string, string> hreflang => sitemap URL. A start page without
     *                               hreflang links gives one sitemap without a language ('').
     */
    public function discoverSitemaps(string $startUrl, string $sitemapPath, int $timeout, array $requestOptions = []): array
    {
        $sitemaps = $this->languageSitemapDiscovery->discover($startUrl, $sitemapPath, $timeout, $requestOptions);

        return $sitemaps !== [] ? $sitemaps : ['' => $this->languageSitemapDiscovery->buildSitemapUrl($startUrl, $sitemapPath)];
    }

    public function buildDefaultLabel(string $startUrl, int $time): string
    {
        $host = parse_url($startUrl, PHP_URL_HOST);

        return (is_string($host) ? $host : 'snapshot') . ' ' . date('Y-m-d H:i', $time);
    }

    /**
     * @param string $label Empty for the default label.
     * @return int uid of the new snapshot
     * @throws SitemapImportException
     */
    public function startSnapshot(string $label, string $startUrl, string $note, int $fetchedAt): int
    {
        $label = trim($label) !== '' ? trim($label) : $this->buildDefaultLabel($startUrl, $fetchedAt);
        if (mb_strlen($label) > self::MAXIMUM_LABEL_LENGTH) {
            throw new SitemapImportException(
                SitemapImportException::REASON_LABEL_TOO_LONG,
                sprintf('The label must not be longer than %d characters.', self::MAXIMUM_LABEL_LENGTH),
            );
        }
        if ($this->sitemapSnapshotRepository->labelExists($label)) {
            throw new SitemapImportException(
                SitemapImportException::REASON_LABEL_EXISTS,
                sprintf('A snapshot named "%s" already exists. Choose another label or delete the existing snapshot.', $label),
            );
        }

        return $this->sitemapSnapshotRepository->createSnapshot($label, $startUrl, trim($note), $fetchedAt);
    }

    /**
     * @param array<string, mixed> $requestOptions
     * @throws SitemapImportException
     */
    public function importLanguage(int $snapshotUid, string $language, string $sitemapUrl, int $timeout, array $requestOptions = []): LanguageImportResult
    {
        $this->assertImporting($snapshotUid);

        $documents = $this->sitemapDocumentCrawler->crawl($sitemapUrl, $timeout, $requestOptions);
        $urlCountsByGroup = [];
        $failedDocuments = [];
        foreach ($documents as $document) {
            $this->sitemapSnapshotRepository->storeDocument($snapshotUid, $language, $document);
            if (!$document->isUsable()) {
                $failedDocuments[] = $document;
            } elseif ($document->type === SitemapDocument::TYPE_URLSET) {
                $urlCountsByGroup[$document->sitemapGroup] = ($urlCountsByGroup[$document->sitemapGroup] ?? 0) + count($document->entries);
            }
        }
        ksort($urlCountsByGroup);

        return new LanguageImportResult($language, $sitemapUrl, count($documents), $urlCountsByGroup, $failedDocuments);
    }

    /**
     * Completes the snapshot. A snapshot without a single page URL is of no use
     * for any comparison and is removed again.
     *
     * @return int number of stored page URLs
     * @throws SitemapImportException
     */
    public function finishSnapshot(int $snapshotUid): int
    {
        $this->assertImporting($snapshotUid);

        $urlCount = $this->sitemapSnapshotRepository->countUrlsOfSnapshot($snapshotUid);
        if ($urlCount === 0) {
            $this->sitemapSnapshotRepository->deleteSnapshot($snapshotUid);
            throw new SitemapImportException(SitemapImportException::REASON_NO_URLS, 'No page URLs found — the snapshot was not stored.');
        }
        $this->sitemapSnapshotRepository->markComplete($snapshotUid);

        return $urlCount;
    }

    private function assertImporting(int $snapshotUid): void
    {
        if ($this->sitemapSnapshotRepository->findByUid($snapshotUid)?->status !== SitemapSnapshot::STATUS_IMPORTING) {
            throw new SitemapImportException(
                SitemapImportException::REASON_SNAPSHOT_NOT_IMPORTING,
                sprintf('Snapshot %d does not exist or is not being imported.', $snapshotUid),
            );
        }
    }
}
