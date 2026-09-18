<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\ArchiveFile;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;

/**
 * Saves snapshots and runs as archive files in the configured directory,
 * describes those files and reads them back — the module side of
 * websitecheck:exportsnapshots and websitecheck:importsnapshots, with the
 * same format and the same import rules.
 *
 * @phpstan-import-type Archive from SnapshotArchive
 * @phpstan-type DescribedEntry array{label: string, originalLabel: string, action: string, environment: string, locked: bool, count: int}
 * @phpstan-type DescribedFile array{name: string, size: int, modifiedAt: int, error: string, snapshots: list<DescribedEntry>, runs: list<DescribedEntry>, importable: bool, conflicts: bool}
 */
class ArchiveFileService
{
    public function __construct(
        private readonly ArchiveDirectory $archiveDirectory,
        private readonly SnapshotArchive $snapshotArchive,
        private readonly SnapshotArchiveExporter $snapshotArchiveExporter,
        private readonly SnapshotArchiveImporter $snapshotArchiveImporter,
    ) {
    }

    /**
     * @throws SnapshotArchiveException
     */
    public function saveSnapshot(string $label): ArchiveFile
    {
        $result = $this->snapshotArchiveExporter->export([$label], false, []);

        return $this->archiveDirectory->write($label, $this->snapshotArchive->encode($result['archive']), time());
    }

    /**
     * A run comes with the snapshots it compared.
     *
     * @throws SnapshotArchiveException
     */
    public function saveRun(string $runLabel): ArchiveFile
    {
        $result = $this->snapshotArchiveExporter->export([], false, [$runLabel]);
        if ($result['archive']['runs'] === []) {
            throw new SnapshotArchiveException(implode(' ', $result['notes']), 1789490401);
        }

        return $this->archiveDirectory->write('run-' . $runLabel, $this->snapshotArchive->encode($result['archive']), time());
    }

    /**
     * Every file with what it holds and what an import would do with it. A
     * file that cannot be read is listed with the reason.
     *
     * @return list<DescribedFile>
     * @throws SnapshotArchiveException when the configured directory is not allowed
     */
    public function describeFiles(): array
    {
        $described = [];
        foreach ($this->archiveDirectory->listFiles() as $file) {
            $entry = ['name' => $file->name, 'size' => $file->size, 'modifiedAt' => $file->modifiedAt, 'error' => '', 'snapshots' => [], 'runs' => [], 'importable' => false, 'conflicts' => false];
            try {
                $archive = $this->read($file);
                $inspection = $this->snapshotArchiveImporter->inspect($archive);
            } catch (SnapshotArchiveException $exception) {
                $entry['error'] = $exception->getMessage();
                $described[] = $entry;
                continue;
            }

            foreach ($archive['snapshots'] as $index => $snapshot) {
                $entry['snapshots'][] = $inspection['snapshots'][$index] + [
                    'environment' => $snapshot['environment'],
                    'locked' => $snapshot['locked'],
                    'count' => array_sum(array_map(static fn(array $document): int => count($document['urls']), $snapshot['documents'])),
                ];
            }
            foreach ($archive['runs'] as $index => $run) {
                $entry['runs'][] = $inspection['runs'][$index] + [
                    'environment' => '',
                    'locked' => false,
                    'count' => count($run['observations']),
                ];
            }
            $actions = array_column([...$entry['snapshots'], ...$entry['runs']], 'action');
            $entry['conflicts'] = in_array(SnapshotArchiveImporter::ACTION_CONFLICT, $actions, true);
            $entry['importable'] = !$entry['conflicts'] && in_array(SnapshotArchiveImporter::ACTION_IMPORT, $actions, true);
            $described[] = $entry;
        }

        return $described;
    }

    /**
     * @return array{snapshots: int, runs: int} how many were imported
     * @throws SnapshotArchiveException
     */
    public function import(string $name): array
    {
        $archive = $this->read($this->archiveDirectory->find($name));
        $plan = $this->snapshotArchiveImporter->plan($archive);
        $this->snapshotArchiveImporter->import($archive, $plan);

        $count = static fn(array $items): int => count(array_filter($items, static fn(array $item): bool => $item['action'] === SnapshotArchiveImporter::ACTION_IMPORT));

        return ['snapshots' => $count($plan['snapshots']), 'runs' => $count($plan['runs'])];
    }

    /**
     * @throws SnapshotArchiveException
     */
    public function delete(string $name): void
    {
        $file = $this->archiveDirectory->find($name);
        if (!unlink($file->path)) {
            throw new SnapshotArchiveException(sprintf('"%s" could not be deleted.', $name), 1789490402);
        }
    }

    /**
     * @return Archive
     * @throws SnapshotArchiveException
     */
    private function read(ArchiveFile $file): array
    {
        $content = file_get_contents($file->path);
        if ($content === false) {
            throw new SnapshotArchiveException(sprintf('"%s" could not be read.', $file->name), 1789490403);
        }

        return $this->snapshotArchive->decode($content);
    }
}
