<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

class CheckResultRepository extends AbstractRepository
{
    public const string TABLE = 'tx_otwebsitecheck_domain_model_check';

    /**
     * Upserts one result per (url, environment). The "reviewed" flag and note
     * survive across runs as long as the HTTP status stays the same — a changed
     * status is a new finding and needs review again.
     *
     * @param int $runStartedAt start of the checksitemap run storing the result, 0 for other commands
     */
    public function storeResult(string $url, string $environment, string $source, ?int $pageUid, int $httpStatus, string $errorMarker, int $checkedAt, int $runStartedAt = 0): void
    {
        $values = [
            'path' => UrlUtility::pathWithQuery($url),
            'source' => $source,
            'page_uid' => $pageUid ?? 0,
            'http_status' => $httpStatus,
            'error_marker' => $errorMarker,
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
     * @return array<int, array<string, mixed>>
     */
    public function findAll(string $environment = '', bool $onlyProblems = false, bool $onlyUnreviewed = false): array
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

        $this->applyFilters($queryBuilder, $environment, $onlyProblems, $onlyUnreviewed);

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * The start of the latest run that stored results for this environment and
     * source, 0 when there is none.
     */
    public function findLatestRunStart(string $environment, string $source): int
    {
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

    private function applyFilters(QueryBuilder $queryBuilder, string $environment, bool $onlyProblems, bool $onlyUnreviewed): void
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
    }
}
