<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

class ObservationRepository extends AbstractRepository
{
    public const string TABLE = 'tx_otwebsitecheck_domain_model_observation';

    private const int INSERT_CHUNK_SIZE = 200;

    /**
     * Everything that describes a row apart from its run: what a copy into
     * another run or an export carries.
     */
    public const array ROW_FIELDS = [
        'environment' => ParameterType::STRING,
        'role' => ParameterType::STRING,
        'sitemap_group' => ParameterType::STRING,
        'requested_url' => ParameterType::STRING,
        'requested_path' => ParameterType::STRING,
        'first_status' => ParameterType::INTEGER,
        'final_url' => ParameterType::STRING,
        'final_path' => ParameterType::STRING,
        'final_status' => ParameterType::INTEGER,
        'hop_count' => ParameterType::INTEGER,
        'redirect_chain' => ParameterType::STRING,
        'abort_reason' => ParameterType::STRING,
        'page_uid' => ParameterType::INTEGER,
        'language' => ParameterType::STRING,
        'record_table' => ParameterType::STRING,
        'record_uid' => ParameterType::INTEGER,
        'verdict' => ParameterType::STRING,
        'warnings' => ParameterType::STRING,
        'suggested_target' => ParameterType::STRING,
        'checked_at' => ParameterType::INTEGER,
        'reviewed' => ParameterType::INTEGER,
        'note' => ParameterType::STRING,
    ];

    /**
     * Stores what was observed for one requested path on one environment.
     * Verdict, warnings and the reviewed flag are left alone here — they
     * depend on the other rows of the run and are set by updateAnalysis().
     */
    public function storeObservation(
        string $runLabel,
        string $environment,
        string $role,
        string $sitemapGroup,
        RedirectChain $redirectChain,
        PageIdentity $identity,
        int $checkedAt,
    ): void {
        $requestedUrl = $redirectChain->getRequestedUrl();
        $requestedPath = UrlUtility::pathWithQuery($requestedUrl);
        $finalUrl = $redirectChain->getFinalUrl();

        $values = [
            'role' => $role,
            'sitemap_group' => $sitemapGroup,
            'requested_url' => $requestedUrl,
            'first_status' => $redirectChain->getFirstStatus(),
            'final_url' => $finalUrl,
            'final_path' => UrlUtility::pathWithQuery($finalUrl),
            'final_status' => $redirectChain->getFinalStatus(),
            'hop_count' => $redirectChain->getHopCount(),
            'redirect_chain' => json_encode($redirectChain->steps, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'abort_reason' => $redirectChain->abortReason,
            'page_uid' => $identity->pageUid,
            'language' => $identity->language,
            'record_table' => $identity->recordTable,
            'record_uid' => $identity->recordUid,
            'checked_at' => $checkedAt,
        ];

        $existingUid = $this->findUid($runLabel, $environment, $requestedPath);
        if ($existingUid === null) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $values + [
                'pid' => 0,
                'run_label' => $runLabel,
                'environment' => $environment,
                'requested_path' => $requestedPath,
                'verdict' => '',
                'warnings' => '',
                'suggested_target' => '',
                'reviewed' => 0,
                'note' => '',
            ]);
            return;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->update(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($existingUid, ParameterType::INTEGER)));
        foreach ($values as $field => $value) {
            $queryBuilder->set($field, $value, true, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * The reviewed flag and note survive a re-run as long as the verdict stays
     * the same; a changed verdict is a new finding and needs review again.
     *
     * @param list<string> $warnings
     */
    public function updateAnalysis(Observation $observation, string $verdict, array $warnings, string $suggestedTarget): void
    {
        $warningsValue = implode(',', $warnings);
        if ($observation->verdict === $verdict
            && implode(',', $observation->warnings) === $warningsValue
            && $observation->suggestedTarget === $suggestedTarget
        ) {
            return;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->update(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($observation->uid, ParameterType::INTEGER)))
            ->set('verdict', $verdict)
            ->set('warnings', $warningsValue)
            ->set('suggested_target', $suggestedTarget);
        if ($observation->verdict !== $verdict) {
            $queryBuilder->set('reviewed', 0, true, ParameterType::INTEGER);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * @return list<Observation>
     */
    public function findByRun(string $runLabel): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $rows = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->orderBy('requested_path', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): Observation => Observation::fromRow($row), $rows);
    }

    /**
     * The rows of a run with the fields of ROW_FIELDS, values typed as there.
     *
     * @param string $role Only rows of this role; all when empty.
     * @return list<array<string, int|string>>
     */
    public function findRowsByRun(string $runLabel, string $role = ''): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->select(...array_keys(self::ROW_FIELDS))
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->orderBy('uid', 'ASC');
        if ($role !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('role', $queryBuilder->createNamedParameter($role)));
        }

        return array_map(self::normalizeRow(...), $queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Writes a row read by findRowsByRun() into a run, replacing the row of
     * the same environment and path there.
     *
     * @param array<string, mixed> $row
     */
    public function storeRow(string $runLabel, array $row): void
    {
        $values = self::normalizeRow($row);
        $existingUid = $this->findUid($runLabel, (string)$values['environment'], (string)$values['requested_path']);
        if ($existingUid === null) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(
                self::TABLE,
                $values + ['pid' => 0, 'run_label' => $runLabel],
            );
            return;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->update(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($existingUid, ParameterType::INTEGER)));
        foreach ($values as $field => $value) {
            $queryBuilder->set($field, $value, true, self::ROW_FIELDS[$field]);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * Adds rows read by findRowsByRun() to a run that has none with the same
     * environment and path.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertRows(string $runLabel, array $rows): void
    {
        $columns = ['pid', 'run_label', ...array_keys(self::ROW_FIELDS)];
        $types = [ParameterType::INTEGER, ParameterType::STRING, ...array_values(self::ROW_FIELDS)];
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $connection->bulkInsert(
                self::TABLE,
                array_map(static fn(array $row): array => [0, $runLabel, ...array_values(self::normalizeRow($row))], $chunk),
                $columns,
                $types,
            );
        }
    }

    /**
     * @return list<string> Most recently checked run first — the one the module opens.
     */
    public function findDistinctRuns(): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $values = $queryBuilder->select('run_label')
            ->addSelectLiteral('MAX(checked_at) AS last_checked_at')
            ->from(self::TABLE)
            ->groupBy('run_label')
            ->orderBy('last_checked_at', 'DESC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $value): string => is_scalar($value) ? (string)$value : '', $values);
    }

    /**
     * @return bool|null The new "reviewed" state, or null if the record does not exist.
     */
    public function toggleReviewed(int $uid): ?bool
    {
        return $this->toggleFlag(self::TABLE, 'reviewed', $uid);
    }

    public function deleteRun(string $runLabel): int
    {
        if ($runLabel === '') {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);

        return $queryBuilder->delete(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->executeStatement();
    }

    private function findUid(string $runLabel, string $environment, string $requestedPath): ?int
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $uid = $queryBuilder->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)),
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
                $queryBuilder->expr()->eq('requested_path', $queryBuilder->createNamedParameter($requestedPath)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($uid) ? (int)$uid : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int|string> exactly the fields of ROW_FIELDS
     */
    private static function normalizeRow(array $row): array
    {
        $values = [];
        foreach (self::ROW_FIELDS as $field => $type) {
            $values[$field] = $type === ParameterType::INTEGER ? RowValue::int($row, $field) : RowValue::string($row, $field);
        }

        return $values;
    }
}
