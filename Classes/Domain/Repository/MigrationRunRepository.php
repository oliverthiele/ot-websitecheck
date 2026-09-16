<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Utility\RowValue;

/**
 * Remembers which sitemap snapshots a migration check run compared, so the
 * results can always be read against the right before and after state.
 */
class MigrationRunRepository extends AbstractRepository
{
    public const string TABLE = 'tx_otwebsitecheck_domain_model_migrationrun';

    /**
     * A re-run with the same label replaces the stored snapshots of the run.
     */
    public function storeRun(string $runLabel, int $referenceSnapshotUid, int $targetSnapshotUid, string $targetHost, int $startedAt): void
    {
        $values = [
            'reference_snapshot' => $referenceSnapshotUid,
            'target_snapshot' => $targetSnapshotUid,
            'target_host' => $targetHost,
            'started_at' => $startedAt,
            'tstamp' => time(),
        ];

        if ($this->findRow($runLabel) === null) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $values + [
                'pid' => 0,
                'crdate' => time(),
                'run_label' => $runLabel,
            ]);
            return;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->update(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)));
        foreach ($values as $field => $value) {
            $queryBuilder->set($field, $value, true, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * @return array{referenceSnapshotUid: int, targetSnapshotUid: int, targetHost: string, startedAt: int}|null
     */
    public function findByLabel(string $runLabel): ?array
    {
        $row = $this->findRow($runLabel);
        if ($row === null) {
            return null;
        }

        return [
            'referenceSnapshotUid' => RowValue::int($row, 'reference_snapshot'),
            'targetSnapshotUid' => RowValue::int($row, 'target_snapshot'),
            'targetHost' => RowValue::string($row, 'target_host'),
            'startedAt' => RowValue::int($row, 'started_at'),
        ];
    }

    /**
     * @return list<int> uids of all snapshots a run compared, as reference or target
     */
    public function findSnapshotUidsInUse(): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $rows = $queryBuilder->select('reference_snapshot', 'target_snapshot')
            ->from(self::TABLE)
            ->executeQuery()
            ->fetchAllAssociative();

        $snapshotUids = [];
        foreach ($rows as $row) {
            $snapshotUids[RowValue::int($row, 'reference_snapshot')] = true;
            $snapshotUids[RowValue::int($row, 'target_snapshot')] = true;
        }
        unset($snapshotUids[0]);

        return array_keys($snapshotUids);
    }

    public function deleteRun(string $runLabel): void
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->delete(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $runLabel): ?array
    {
        if ($runLabel === '') {
            return null;
        }
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
