<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;

/**
 * Collects sitemap snapshots and migration check runs for an archive.
 *
 * A run brings the snapshots it compared, so its results can be read against
 * them after an import. Records without a uuid get one here — an archive
 * identifies everything by uuid.
 *
 * @phpstan-import-type Archive from SnapshotArchive
 * @phpstan-import-type ArchivedSnapshot from SnapshotArchive
 * @phpstan-import-type ArchivedRun from SnapshotArchive
 */
class SnapshotArchiveExporter
{
    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly MigrationRunRepository $migrationRunRepository,
        private readonly ObservationRepository $observationRepository,
    ) {
    }

    /**
     * @param list<string> $snapshotLabels
     * @param bool $lockedSnapshots Also every locked, complete snapshot.
     * @param list<string> $runLabels
     * @return array{archive: Archive, notes: list<string>}
     * @throws SnapshotArchiveException when a named snapshot or run does not exist or a snapshot is incomplete
     */
    public function export(array $snapshotLabels, bool $lockedSnapshots, array $runLabels): array
    {
        $notes = [];
        /** @var array<int, SitemapSnapshot> $snapshots uid => snapshot */
        $snapshots = [];
        foreach ($snapshotLabels as $label) {
            $snapshot = $this->sitemapSnapshotRepository->findByLabel($label);
            if ($snapshot === null) {
                throw new SnapshotArchiveException(sprintf('There is no sitemap snapshot named "%s".', $label), 1789490101);
            }
            $snapshots[$snapshot->uid] = $this->assertComplete($snapshot);
        }
        if ($lockedSnapshots) {
            foreach ($this->sitemapSnapshotRepository->findAll() as $snapshot) {
                if (!$snapshot->locked) {
                    continue;
                }
                if (!$snapshot->isComplete()) {
                    $notes[] = sprintf('The locked snapshot "%s" is skipped: its import did not finish.', $snapshot->label);
                    continue;
                }
                $snapshots[$snapshot->uid] = $snapshot;
            }
        }

        $runs = [];
        foreach ($runLabels as $label) {
            $run = $this->migrationRunRepository->findByLabel($label);
            if ($run === null) {
                throw new SnapshotArchiveException(sprintf('There is no migration check run named "%s".', $label), 1789490102);
            }
            foreach ([$run['referenceSnapshotUid'], $run['targetSnapshotUid']] as $snapshotUid) {
                if ($snapshotUid === 0 || isset($snapshots[$snapshotUid])) {
                    continue;
                }
                $snapshot = $this->sitemapSnapshotRepository->findByUid($snapshotUid);
                if ($snapshot === null) {
                    $notes[] = sprintf('Run "%s" compared a snapshot that no longer exists.', $label);
                    continue;
                }
                $snapshots[$snapshotUid] = $this->assertComplete($snapshot);
            }
            $runs[] = $run;
        }

        if ($snapshots === [] && $runs === []) {
            throw new SnapshotArchiveException('Nothing to export: name at least one snapshot or run, or use --locked with locked snapshots present.', 1789490103);
        }

        /** @var array<int, string> $uuidBySnapshotUid */
        $uuidBySnapshotUid = [];
        $archivedSnapshots = [];
        foreach ($snapshots as $snapshot) {
            $uuidBySnapshotUid[$snapshot->uid] = $this->sitemapSnapshotRepository->ensureUuid($snapshot);
            $archivedSnapshots[] = $this->archiveSnapshot($snapshot, $uuidBySnapshotUid[$snapshot->uid]);
        }

        $archivedRuns = [];
        foreach ($runs as $run) {
            $referenceUuid = $uuidBySnapshotUid[$run['referenceSnapshotUid']] ?? '';
            if ($referenceUuid === '') {
                $notes[] = sprintf('Run "%s" is skipped: its reference snapshot no longer exists.', $run['runLabel']);
                continue;
            }
            $archivedRuns[] = [
                'uuid' => $this->migrationRunRepository->ensureUuid($run),
                'label' => $run['runLabel'],
                'referenceSnapshot' => $referenceUuid,
                'targetSnapshot' => $uuidBySnapshotUid[$run['targetSnapshotUid']] ?? '',
                'targetHost' => $run['targetHost'],
                'startedAt' => $run['startedAt'],
                'observations' => $this->observationRepository->findRowsByRun($run['runLabel']),
            ];
        }

        return [
            'archive' => ['createdAt' => time(), 'snapshots' => $archivedSnapshots, 'runs' => $archivedRuns],
            'notes' => $notes,
        ];
    }

    private function assertComplete(SitemapSnapshot $snapshot): SitemapSnapshot
    {
        if (!$snapshot->isComplete()) {
            throw new SnapshotArchiveException(sprintf('The import of snapshot "%s" did not finish; it cannot be exported.', $snapshot->label), 1789490104);
        }

        return $snapshot;
    }

    /**
     * @return ArchivedSnapshot
     */
    private function archiveSnapshot(SitemapSnapshot $snapshot, string $uuid): array
    {
        $documents = [];
        foreach ($this->sitemapSnapshotRepository->findDocumentsWithUrls($snapshot->uid) as $entry) {
            $document = $entry['document'];
            $documents[] = [
                'language' => $entry['language'],
                'url' => $document->url,
                'parentUrl' => $document->parentUrl,
                'sitemapGroup' => $document->sitemapGroup,
                'type' => $document->type,
                'httpStatus' => $document->httpStatus,
                'body' => $document->body,
                'urls' => $document->entries,
            ];
        }

        return [
            'uuid' => $uuid,
            'label' => $snapshot->label,
            'environment' => $snapshot->environment,
            'startUrl' => $snapshot->startUrl,
            'note' => $snapshot->note,
            'locked' => $snapshot->locked,
            'fetchedAt' => $snapshot->fetchedAt,
            'documents' => $documents,
        ];
    }
}
