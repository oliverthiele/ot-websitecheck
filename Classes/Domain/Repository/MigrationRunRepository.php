<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use Symfony\Component\Uid\Uuid;

/**
 * Remembers which sitemap snapshots a migration check run compared, so the
 * results can always be read against the right before and after state.
 */
class MigrationRunRepository extends AbstractRepository
{
    public const string TABLE = 'tx_otwebsitecheck_domain_model_migrationrun';

    /**
     * A re-run with the same label replaces the stored snapshots of the run
     * and keeps its uuid.
     *
     * @param string $uuid Kept when a run is restored from an export; a new one otherwise.
     */
    public function storeRun(string $runLabel, int $referenceSnapshotUid, int $targetSnapshotUid, string $targetHost, int $startedAt, string $uuid = ''): void
    {
        $values = [
            'reference_snapshot' => $referenceSnapshotUid,
            'target_snapshot' => $targetSnapshotUid,
            'target_host' => $targetHost,
            'started_at' => $startedAt,
            'tstamp' => time(),
        ];

        if ($this->findRow('run_label', $runLabel) === null) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $values + [
                'pid' => 0,
                'crdate' => time(),
                'run_label' => $runLabel,
                'uuid' => $uuid !== '' ? $uuid : Uuid::v7()->toRfc4122(),
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
     * @return array{uid: int, uuid: string, runLabel: string, referenceSnapshotUid: int, targetSnapshotUid: int, targetHost: string, startedAt: int}|null
     */
    public function findByLabel(string $runLabel): ?array
    {
        $row = $this->findRow('run_label', $runLabel);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @return array{uid: int, uuid: string, runLabel: string, referenceSnapshotUid: int, targetSnapshotUid: int, targetHost: string, startedAt: int}|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $row = $this->findRow('uuid', $uuid);

        return $row === null ? null : $this->mapRow($row);
    }

    /**
     * @return list<array{uid: int, uuid: string, runLabel: string, referenceSnapshotUid: int, targetSnapshotUid: int, targetHost: string, startedAt: int}> newest first
     */
    public function findAll(): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $rows = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->orderBy('started_at', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /**
     * Runs created before the uuid column existed get one on first use.
     *
     * @param array{uid: int, uuid: string} $run
     * @return string the uuid of the run
     */
    public function ensureUuid(array $run): string
    {
        return $this->assignMissingUuid(self::TABLE, $run['uid'], $run['uuid']);
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
     * @param array<string, mixed> $row
     * @return array{uid: int, uuid: string, runLabel: string, referenceSnapshotUid: int, targetSnapshotUid: int, targetHost: string, startedAt: int}
     */
    private function mapRow(array $row): array
    {
        return [
            'uid' => RowValue::int($row, 'uid'),
            'uuid' => RowValue::string($row, 'uuid'),
            'runLabel' => RowValue::string($row, 'run_label'),
            'referenceSnapshotUid' => RowValue::int($row, 'reference_snapshot'),
            'targetSnapshotUid' => RowValue::int($row, 'target_snapshot'),
            'targetHost' => RowValue::string($row, 'target_host'),
            'startedAt' => RowValue::int($row, 'started_at'),
        ];
    }

    /**
     * @param 'run_label'|'uuid' $field
     * @return array<string, mixed>|null
     */
    private function findRow(string $field, string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
