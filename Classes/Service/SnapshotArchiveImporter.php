<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Writes the snapshots and runs of an archive into this database.
 *
 * What is already here — the same uuid — is skipped, so an archive can be
 * imported again without doubling anything. A label that is taken by a
 * different record is a conflict: the import stops before writing anything,
 * unless a suffix is given that makes the imported labels unique.
 *
 * Everything is written in one transaction; a failing import leaves nothing
 * behind.
 *
 * @phpstan-import-type Archive from SnapshotArchive
 * @phpstan-import-type ArchivedSnapshot from SnapshotArchive
 * @phpstan-import-type ArchivedRun from SnapshotArchive
 * @phpstan-type PlannedItem array{label: string, originalLabel: string, action: 'import'|'skip'}
 * @phpstan-type ImportPlan array{snapshots: list<PlannedItem>, runs: list<PlannedItem>}
 */
class SnapshotArchiveImporter
{
    public const string ACTION_IMPORT = 'import';
    public const string ACTION_SKIP = 'skip';

    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly MigrationRunRepository $migrationRunRepository,
        private readonly ObservationRepository $observationRepository,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * What an import would do, in the order of the archive.
     *
     * @param Archive $archive
     * @param string $labelSuffix Appended to every imported label that is taken already.
     * @return ImportPlan
     * @throws SnapshotArchiveException on a label conflict
     */
    public function plan(array $archive, string $labelSuffix = ''): array
    {
        $conflicts = [];
        $existingRunLabels = array_flip($this->observationRepository->findDistinctRuns());

        $snapshots = [];
        foreach ($archive['snapshots'] as $snapshot) {
            $existingSnapshot = $this->sitemapSnapshotRepository->findByUuid($snapshot['uuid']);
            if ($existingSnapshot !== null) {
                // The same snapshot, even if it was renamed here since.
                $snapshots[] = ['label' => $existingSnapshot->label, 'originalLabel' => $snapshot['label'], 'action' => self::ACTION_SKIP];
                continue;
            }
            $label = $this->resolveLabel(
                $snapshot['label'],
                $labelSuffix,
                fn(string $candidate): bool => $this->sitemapSnapshotRepository->labelExists($candidate),
            );
            if ($label === null) {
                $conflicts[] = sprintf('snapshot "%s"', $snapshot['label']);
                continue;
            }
            if (mb_strlen($label) > SitemapSnapshotImporter::MAXIMUM_LABEL_LENGTH) {
                throw new SnapshotArchiveException(sprintf('The label "%s" is longer than %d characters.', $label, SitemapSnapshotImporter::MAXIMUM_LABEL_LENGTH), 1789490201);
            }
            $snapshots[] = ['label' => $label, 'originalLabel' => $snapshot['label'], 'action' => self::ACTION_IMPORT];
        }

        $runs = [];
        foreach ($archive['runs'] as $run) {
            $existingRun = $this->migrationRunRepository->findByUuid($run['uuid']);
            if ($existingRun !== null) {
                $runs[] = ['label' => $existingRun['runLabel'], 'originalLabel' => $run['label'], 'action' => self::ACTION_SKIP];
                continue;
            }
            $label = $this->resolveLabel(
                $run['label'],
                $labelSuffix,
                fn(string $candidate): bool => isset($existingRunLabels[$candidate]) || $this->migrationRunRepository->findByLabel($candidate) !== null,
            );
            if ($label === null) {
                $conflicts[] = sprintf('run "%s"', $run['label']);
                continue;
            }
            $runs[] = ['label' => $label, 'originalLabel' => $run['label'], 'action' => self::ACTION_IMPORT];
        }

        if ($conflicts !== []) {
            throw new SnapshotArchiveException(sprintf(
                'These labels are already used by other records: %s. Delete those records or import with --label-suffix.',
                implode(', ', $conflicts),
            ), 1789490202);
        }

        return ['snapshots' => $snapshots, 'runs' => $runs];
    }

    /**
     * @param Archive $archive
     * @param ImportPlan $plan as returned by plan() for the same archive
     */
    public function import(array $archive, array $plan): void
    {
        $connection = $this->connectionPool->getConnectionForTable(SitemapSnapshotRepository::TABLE_SNAPSHOT);
        $connection->beginTransaction();
        try {
            foreach ($archive['snapshots'] as $index => $snapshot) {
                if ($plan['snapshots'][$index]['action'] === self::ACTION_IMPORT) {
                    $this->importSnapshot($snapshot, $plan['snapshots'][$index]['label']);
                }
            }
            foreach ($archive['runs'] as $index => $run) {
                if ($plan['runs'][$index]['action'] === self::ACTION_IMPORT) {
                    $this->importRun($run, $plan['runs'][$index]['label']);
                }
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    /**
     * @param callable(string): bool $isTaken
     * @return string|null the label to use, or null on a conflict
     */
    private function resolveLabel(string $label, string $labelSuffix, callable $isTaken): ?string
    {
        if (!$isTaken($label)) {
            return $label;
        }
        if ($labelSuffix === '' || $isTaken($label . $labelSuffix)) {
            return null;
        }

        return $label . $labelSuffix;
    }

    /**
     * @param ArchivedSnapshot $snapshot
     */
    private function importSnapshot(array $snapshot, string $label): void
    {
        $snapshotUid = $this->sitemapSnapshotRepository->createSnapshot($label, $snapshot['startUrl'], $snapshot['note'], $snapshot['fetchedAt'], $snapshot['uuid']);
        foreach ($snapshot['documents'] as $document) {
            $this->sitemapSnapshotRepository->storeDocument($snapshotUid, $document['language'], new SitemapDocument(
                url: $document['url'],
                parentUrl: $document['parentUrl'],
                sitemapGroup: $document['sitemapGroup'],
                type: $document['type'],
                httpStatus: $document['httpStatus'],
                body: $document['body'],
                entries: $document['urls'],
            ));
        }
        $this->sitemapSnapshotRepository->markComplete($snapshotUid, $snapshot['locked']);
    }

    /**
     * @param ArchivedRun $run
     */
    private function importRun(array $run, string $label): void
    {
        $this->migrationRunRepository->storeRun(
            $label,
            $this->sitemapSnapshotRepository->findByUuid($run['referenceSnapshot'])->uid ?? 0,
            $this->sitemapSnapshotRepository->findByUuid($run['targetSnapshot'])->uid ?? 0,
            $run['targetHost'],
            $run['startedAt'],
            $run['uuid'],
        );
        // plan() made sure the run label is unused, so nothing needs replacing.
        $this->observationRepository->insertRows($label, $run['observations']);
    }
}
