<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

/**
 * Stores sitemap snapshots with their documents and page URLs. Counts per
 * language and group are always derived from the stored URLs, never stored,
 * so they cannot drift from what the snapshot actually contains.
 */
class SitemapSnapshotRepository extends AbstractRepository
{
    public const string TABLE_SNAPSHOT = 'tx_otwebsitecheck_domain_model_sitemapsnapshot';
    public const string TABLE_DOCUMENT = 'tx_otwebsitecheck_domain_model_sitemapdocument';
    public const string TABLE_URL = 'tx_otwebsitecheck_domain_model_sitemapurl';

    private const int INSERT_CHUNK_SIZE = 500;

    public function labelExists(string $label): bool
    {
        return $this->findByLabel($label) !== null;
    }

    public function createSnapshot(string $label, string $startUrl, string $note, int $fetchedAt): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_SNAPSHOT);
        $now = time();
        $connection->insert(self::TABLE_SNAPSHOT, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'label' => $label,
            'start_url' => $startUrl,
            'fetched_at' => $fetchedAt,
            'status' => SitemapSnapshot::STATUS_IMPORTING,
            'note' => $note,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * Stores the document with its raw body and, for a urlset, one row per page URL.
     */
    public function storeDocument(int $snapshotUid, string $language, SitemapDocument $document): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_DOCUMENT);
        $now = time();
        $connection->insert(self::TABLE_DOCUMENT, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'snapshot' => $snapshotUid,
            'language' => $language,
            'url' => $document->url,
            'parent_url' => $document->parentUrl,
            'sitemap_group' => $document->sitemapGroup,
            'document_type' => $document->type,
            'http_status' => $document->httpStatus,
            'body' => $document->body,
        ]);
        $documentUid = (int)$connection->lastInsertId();

        $rows = [];
        foreach ($document->entries as $entry) {
            $rows[] = [0, $snapshotUid, $documentUid, $language, $document->sitemapGroup, $entry['url'], UrlUtility::pathWithQuery($entry['url']), $entry['lastmod']];
        }
        $urlConnection = $this->connectionPool->getConnectionForTable(self::TABLE_URL);
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $urlConnection->bulkInsert(
                self::TABLE_URL,
                $chunk,
                ['pid', 'snapshot', 'document', 'language', 'sitemap_group', 'url', 'path', 'lastmod'],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
            );
        }
    }

    /**
     * @return list<SitemapSnapshot> Newest first.
     */
    public function findAll(): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE_SNAPSHOT);
        $rows = $queryBuilder->select('*')
            ->from(self::TABLE_SNAPSHOT)
            ->orderBy('fetched_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): SitemapSnapshot => SitemapSnapshot::fromRow($row), $rows);
    }

    public function findByUid(int $snapshotUid): ?SitemapSnapshot
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE_SNAPSHOT);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE_SNAPSHOT)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($snapshotUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? SitemapSnapshot::fromRow($row) : null;
    }

    public function findByLabel(string $label): ?SitemapSnapshot
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE_SNAPSHOT);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE_SNAPSHOT)
            ->where($queryBuilder->expr()->eq('label', $queryBuilder->createNamedParameter($label)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? SitemapSnapshot::fromRow($row) : null;
    }

    /**
     * @return array<string, string> page URL => sitemap group, in import order; a URL listed twice keeps its first group
     */
    public function findUrls(int $snapshotUid): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE_URL);
        $rows = $queryBuilder->select('url', 'sitemap_group')
            ->from(self::TABLE_URL)
            ->where($queryBuilder->expr()->eq('snapshot', $queryBuilder->createNamedParameter($snapshotUid, ParameterType::INTEGER)))
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $urls = [];
        foreach ($rows as $row) {
            $urls[RowValue::string($row, 'url')] ??= RowValue::string($row, 'sitemap_group');
        }

        return $urls;
    }

    /**
     * @param bool $lock Also lock the snapshot; false leaves the lock as it is.
     */
    public function markComplete(int $snapshotUid, bool $lock = false): void
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE_SNAPSHOT);
        $queryBuilder->update(self::TABLE_SNAPSHOT)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($snapshotUid, ParameterType::INTEGER)))
            ->set('status', SitemapSnapshot::STATUS_COMPLETE)
            ->set('tstamp', time(), true, ParameterType::INTEGER);
        if ($lock) {
            $queryBuilder->set('locked', 1, true, ParameterType::INTEGER);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * @return bool|null The new "locked" state, or null if no snapshot with this uid exists.
     */
    public function toggleLocked(int $snapshotUid): ?bool
    {
        return $this->toggleFlag(self::TABLE_SNAPSHOT, 'locked', $snapshotUid);
    }

    public function countUrlsOfSnapshot(int $snapshotUid): int
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE_URL);
        $count = $queryBuilder->count('uid')
            ->from(self::TABLE_URL)
            ->where($queryBuilder->expr()->eq('snapshot', $queryBuilder->createNamedParameter($snapshotUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * Every stored sitemap file without its raw body, with the number of page
     * URLs it lists.
     *
     * @return array<int, list<array{uid: int, language: string, url: string, parentUrl: string, sitemapGroup: string, type: string, httpStatus: int, urlCount: int}>> snapshot uid => documents in import order
     */
    public function findDocumentSummaries(): array
    {
        $countQueryBuilder = $this->createQueryBuilder(self::TABLE_URL);
        $countRows = $countQueryBuilder->select('document')
            ->addSelectLiteral('COUNT(*) AS url_count')
            ->from(self::TABLE_URL)
            ->groupBy('document')
            ->executeQuery()
            ->fetchAllAssociative();
        $urlCounts = [];
        foreach ($countRows as $countRow) {
            $urlCounts[RowValue::int($countRow, 'document')] = RowValue::int($countRow, 'url_count');
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE_DOCUMENT);
        $rows = $queryBuilder->select('uid', 'snapshot', 'language', 'url', 'parent_url', 'sitemap_group', 'document_type', 'http_status')
            ->from(self::TABLE_DOCUMENT)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $documents = [];
        foreach ($rows as $row) {
            $uid = RowValue::int($row, 'uid');
            $documents[RowValue::int($row, 'snapshot')][] = [
                'uid' => $uid,
                'language' => RowValue::string($row, 'language'),
                'url' => RowValue::string($row, 'url'),
                'parentUrl' => RowValue::string($row, 'parent_url'),
                'sitemapGroup' => RowValue::string($row, 'sitemap_group'),
                'type' => RowValue::string($row, 'document_type'),
                'httpStatus' => RowValue::int($row, 'http_status'),
                'urlCount' => $urlCounts[$uid] ?? 0,
            ];
        }

        return $documents;
    }

    /**
     * Deletes the snapshot together with its documents and URLs. A locked
     * snapshot is never deleted, whoever asks — it may be the only remaining
     * copy of a state the live site no longer delivers.
     *
     * @return bool false if no snapshot with this uid exists or it is locked.
     */
    public function deleteSnapshot(int $snapshotUid): bool
    {
        if ($snapshotUid <= 0 || ($this->findByUid($snapshotUid)->locked ?? true)) {
            return false;
        }

        foreach ([self::TABLE_URL, self::TABLE_DOCUMENT] as $table) {
            $queryBuilder = $this->createQueryBuilder($table);
            $queryBuilder->delete($table)
                ->where($queryBuilder->expr()->eq('snapshot', $queryBuilder->createNamedParameter($snapshotUid, ParameterType::INTEGER)))
                ->executeStatement();
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE_SNAPSHOT);

        return $queryBuilder->delete(self::TABLE_SNAPSHOT)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($snapshotUid, ParameterType::INTEGER)))
            ->executeStatement() > 0;
    }
}
