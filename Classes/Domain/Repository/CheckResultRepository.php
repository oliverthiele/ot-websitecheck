<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Service\FindingGuide;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Registry;

class CheckResultRepository extends AbstractRepository
{
    public const string TABLE = 'tx_otwebsitecheck_domain_model_check';

    /**
     * What a result without a marker must answer to match an actor filter.
     */
    public const string PLAIN_NOT_FOUND = 'notFound';
    public const string PLAIN_OTHER_ERROR = 'otherError';
    public const string PLAIN_OK = 'ok';
    private const string REGISTRY_NAMESPACE = 'ot_websitecheck';

    public function __construct(
        ConnectionPool $connectionPool,
        private readonly Registry $registry,
    ) {
        parent::__construct($connectionPool);
    }

    /**
     * Upserts one result per (url, environment). The "reviewed" flag and note
     * survive across runs as long as the HTTP status stays the same — a changed
     * status is a new finding and needs review again.
     *
     * @param int $runStartedAt start of the checksitemap run storing the result, 0 for other commands
     * @param string $finalUrl the URL that answered after redirects; empty without redirects
     * @param string $canonicalUrl the canonical URL the page declares; empty when it declares none or was not read
     * @param int $languageUid the site language the local routing reads from the URL
     */
    public function storeResult(
        string $url,
        string $environment,
        string $source,
        ?int $pageUid,
        int $httpStatus,
        string $errorMarker,
        int $checkedAt,
        int $runStartedAt = 0,
        string $finalUrl = '',
        string $canonicalUrl = '',
        int $languageUid = 0,
    ): void {
        $values = [
            'path' => UrlUtility::pathWithQuery($url),
            'source' => $source,
            'page_uid' => $pageUid ?? 0,
            'language_uid' => $languageUid,
            'http_status' => $httpStatus,
            'error_marker' => $errorMarker,
            'final_url' => $finalUrl,
            'canonical_url' => $canonicalUrl,
            'checked_at' => $checkedAt,
            'run_started_at' => $runStartedAt,
        ];

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $existingRow = $queryBuilder->select('uid', 'http_status')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($url)),
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($existingRow === false) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $values + [
                'pid' => 0,
                'url' => $url,
                'environment' => $environment,
                'reviewed' => 0,
                'note' => '',
            ]);
            return;
        }

        if (RowValue::int($existingRow, 'http_status') !== $httpStatus) {
            $values['reviewed'] = 0;
        }
        $updateQueryBuilder = $this->createQueryBuilder(self::TABLE);
        $updateQueryBuilder->update(self::TABLE)
            ->where($updateQueryBuilder->expr()->eq('uid', $updateQueryBuilder->createNamedParameter(RowValue::int($existingRow, 'uid'), ParameterType::INTEGER)));
        foreach ($values as $field => $value) {
            $updateQueryBuilder->set($field, $value, true, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $updateQueryBuilder->executeStatement();
    }

    /**
     * @param int $limit rows per page, 0 for all
     * @param list<string> $markers only rows with one of these markers; all when empty
     * @param array{markers: list<string>, plain: string}|null $actorFilter rows with one of these markers, or without a marker and a status as named by "plain" (a PLAIN_* constant or ''); all when null
     * @return array<int, array<string, mixed>>
     */
    public function findAll(string $environment = '', bool $onlyProblems = false, bool $onlyUnreviewed = false, int $limit = 0, int $offset = 0, array $markers = [], ?array $actorFilter = null): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        // Group by page uid first, then path: the same page uid/path can carry
        // a different url string per environment (different host, or a slug
        // that was corrected on one system but not the other), so sorting by
        // url would scatter matching rows instead of lining them up for
        // comparison across environments.
        $queryBuilder->select('*')->from(self::TABLE)
            ->orderBy('page_uid', 'ASC')
            ->addOrderBy('path', 'ASC')
            ->addOrderBy('environment', 'ASC');

        $this->applyFilters($queryBuilder, $environment, $onlyProblems, $onlyUnreviewed, $markers, $actorFilter);
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit)->setFirstResult(max(0, $offset));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<string> $markers only rows with one of these markers; all when empty
     * @param array{markers: list<string>, plain: string}|null $actorFilter see findAll()
     */
    public function countAll(string $environment = '', bool $onlyProblems = false, bool $onlyUnreviewed = false, array $markers = [], ?array $actorFilter = null): int
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->count('uid')->from(self::TABLE);
        $this->applyFilters($queryBuilder, $environment, $onlyProblems, $onlyUnreviewed, $markers, $actorFilter);
        $count = $queryBuilder->executeQuery()->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * Notes that a run has started, before it stores its first result. A run
     * that is aborted before that would otherwise leave no trace, and the
     * latest run with results — possibly a complete one — would pass as the
     * run to resume.
     */
    public function registerRunStart(string $environment, string $source, int $runStartedAt): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, $this->runRegistryKey($environment, $source), $runStartedAt);
    }

    /**
     * The start of the latest run for this environment and source, 0 when
     * there is none. Runs started before runs were registered are found by
     * their results.
     */
    public function findLatestRunStart(string $environment, string $source): int
    {
        $registered = $this->registry->get(self::REGISTRY_NAMESPACE, $this->runRegistryKey($environment, $source));
        if (is_int($registered) && $registered > 0) {
            return $registered;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $latest = $queryBuilder
            ->selectLiteral($queryBuilder->expr()->max('run_started_at'))
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
                $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($source)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($latest) ? (int)$latest : 0;
    }

    /**
     * @return array<string, true> URLs whose result was stored by the run that started at $runStartedAt
     */
    public function findUrlsOfRun(string $environment, string $source, int $runStartedAt): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $urls = $queryBuilder
            ->select('url')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
                $queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($source)),
                $queryBuilder->expr()->eq('run_started_at', $queryBuilder->createNamedParameter($runStartedAt, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $result = [];
        foreach ($urls as $url) {
            if (is_string($url)) {
                $result[$url] = true;
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function findDistinctEnvironments(): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $rows = $queryBuilder
            ->selectLiteral('DISTINCT environment')
            ->from(self::TABLE)
            ->orderBy('environment', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $environment): string => is_scalar($environment) ? (string)$environment : '', $rows);
    }

    /**
     * @return list<string> the markers stored for an environment, or for all
     */
    public function findDistinctMarkers(string $environment = ''): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->selectLiteral('DISTINCT error_marker')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->neq('error_marker', $queryBuilder->createNamedParameter('')))
            ->orderBy('error_marker', 'ASC');
        if ($environment !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
            );
        }
        $rows = $queryBuilder->executeQuery()->fetchFirstColumn();

        return array_map(static fn(mixed $marker): string => is_scalar($marker) ? (string)$marker : '', $rows);
    }

    /**
     * @return array<int, string>
     */
    public function findDistinctSources(string $environment = ''): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->selectLiteral('DISTINCT source')->from(self::TABLE)->orderBy('source', 'ASC');
        if ($environment !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
            );
        }
        $rows = $queryBuilder->executeQuery()->fetchFirstColumn();

        return array_map(static fn(mixed $source): string => is_scalar($source) ? (string)$source : '', $rows);
    }

    /**
     * @return array{total: int, notOk: int, unreviewedNotOk: int}
     */
    public function summarize(string $environment = ''): array
    {
        $totalQueryBuilder = $this->createQueryBuilder(self::TABLE);
        $totalQueryBuilder->count('uid')->from(self::TABLE);
        $this->applyFilters($totalQueryBuilder, $environment, false, false);
        $total = $totalQueryBuilder->executeQuery()->fetchOne();

        $notOkQueryBuilder = $this->createQueryBuilder(self::TABLE);
        $notOkQueryBuilder->count('uid')->from(self::TABLE);
        $this->applyFilters($notOkQueryBuilder, $environment, true, false);
        $notOk = $notOkQueryBuilder->executeQuery()->fetchOne();

        $unreviewedNotOkQueryBuilder = $this->createQueryBuilder(self::TABLE);
        $unreviewedNotOkQueryBuilder->count('uid')->from(self::TABLE);
        $this->applyFilters($unreviewedNotOkQueryBuilder, $environment, true, true);
        $unreviewedNotOk = $unreviewedNotOkQueryBuilder->executeQuery()->fetchOne();

        return [
            'total' => is_numeric($total) ? (int)$total : 0,
            'notOk' => is_numeric($notOk) ? (int)$notOk : 0,
            'unreviewedNotOk' => is_numeric($unreviewedNotOk) ? (int)$unreviewedNotOk : 0,
        ];
    }

    public function deleteByUid(int $uid): void
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->delete(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeStatement();
    }

    /**
     * @return bool|null The new "reviewed" state, or null if the record does not exist.
     */
    public function toggleReviewed(int $uid): ?bool
    {
        return $this->toggleFlag(self::TABLE, 'reviewed', $uid);
    }

    public function deleteAll(string $environment = ''): int
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->delete(self::TABLE);
        if ($environment !== '') {
            $queryBuilder->where(
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
            );
        }

        return $queryBuilder->executeStatement();
    }

    /**
     * Environment and source are free text of any length; the registry key
     * column is not.
     */
    private function runRegistryKey(string $environment, string $source): string
    {
        return 'checksitemap.run.' . hash('sha256', $environment . "\n" . $source);
    }

    /**
     * @param list<string> $markers
     * @param array{markers: list<string>, plain: string}|null $actorFilter
     */
    private function applyFilters(QueryBuilder $queryBuilder, string $environment, bool $onlyProblems, bool $onlyUnreviewed, array $markers = [], ?array $actorFilter = null): void
    {
        if ($environment !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
            );
        }
        if ($onlyProblems) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->neq('http_status', $queryBuilder->createNamedParameter(200, ParameterType::INTEGER)),
                    $queryBuilder->expr()->neq('error_marker', $queryBuilder->createNamedParameter('')),
                ),
            );
        }
        if ($onlyUnreviewed) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('reviewed', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            );
        }
        if ($markers !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('error_marker', $queryBuilder->createNamedParameter($markers, ArrayParameterType::STRING)),
            );
        }
        if ($actorFilter !== null) {
            $queryBuilder->andWhere($this->buildActorConstraint($queryBuilder, $actorFilter));
        }
    }

    /**
     * @param array{markers: list<string>, plain: string} $actorFilter
     */
    private function buildActorConstraint(QueryBuilder $queryBuilder, array $actorFilter): string
    {
        $expr = $queryBuilder->expr();
        $alternatives = [];
        if ($actorFilter['markers'] !== []) {
            $alternatives[] = $expr->in('error_marker', $queryBuilder->createNamedParameter($actorFilter['markers'], ArrayParameterType::STRING));
        }
        $notFoundStatuses = $queryBuilder->createNamedParameter(FindingGuide::NOT_FOUND_STATUSES, ArrayParameterType::INTEGER);
        $statusConstraint = match ($actorFilter['plain']) {
            self::PLAIN_NOT_FOUND => $expr->in('http_status', $notFoundStatuses),
            self::PLAIN_OTHER_ERROR => $expr->and(
                $expr->neq('http_status', $queryBuilder->createNamedParameter(200, ParameterType::INTEGER)),
                $expr->notIn('http_status', $notFoundStatuses),
            ),
            self::PLAIN_OK => $expr->eq('http_status', $queryBuilder->createNamedParameter(200, ParameterType::INTEGER)),
            default => null,
        };
        if ($statusConstraint !== null) {
            $alternatives[] = $expr->and(
                $expr->eq('error_marker', $queryBuilder->createNamedParameter('')),
                $statusConstraint,
            );
        }

        return $alternatives === [] ? '1 = 0' : (string)$expr->or(...$alternatives);
    }
}
