<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Service\SnapshotRetentionPolicy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes old sitemap snapshots, the counterpart of a scheduled import. Which
 * ones is decided by SnapshotRetentionPolicy; --dry-run lists exactly the
 * snapshots a real run would delete.
 */
class CleanupSnapshotsCommand extends Command
{
    use CommandInputTrait;

    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly MigrationRunRepository $migrationRunRepository,
        private readonly SnapshotRetentionPolicy $snapshotRetentionPolicy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Remove old sitemap snapshots; snapshots with a note or used by a migration check run are kept.');
        $this->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Number of complete snapshots kept per start URL.', '10');
        $this->addOption('incomplete-hours', null, InputOption::VALUE_REQUIRED, 'Remove unfinished imports older than this many hours.', '24');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the snapshots that would be removed, remove nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keep = $this->intValue($input->getOption('keep'), 10);
        $incompleteHours = $this->intValue($input->getOption('incomplete-hours'), 24);
        if ($keep < 1 || $incompleteHours < 1) {
            $io->error('--keep and --incomplete-hours must be at least 1.');
            return self::FAILURE;
        }
        $dryRun = $input->getOption('dry-run') === true;

        $selected = $this->snapshotRetentionPolicy->selectForDeletion(
            $this->sitemapSnapshotRepository->findAll(),
            $this->migrationRunRepository->findSnapshotUidsInUse(),
            $keep,
            time() - $incompleteHours * 3600,
        );
        if ($selected === []) {
            $io->success('Nothing to remove.');
            return self::SUCCESS;
        }

        $io->table(
            ['Snapshot', 'Start URL', 'Fetched at', 'Status'],
            array_map(static fn(SitemapSnapshot $snapshot): array => [$snapshot->label, $snapshot->startUrl, date('Y-m-d H:i', $snapshot->fetchedAt), $snapshot->status], $selected),
        );
        if ($dryRun) {
            $io->note(sprintf('Dry run: %d snapshots would be removed.', count($selected)));
            return self::SUCCESS;
        }

        foreach ($selected as $snapshot) {
            $this->sitemapSnapshotRepository->deleteSnapshot($snapshot->uid);
        }
        $io->success(sprintf('Removed %d snapshots.', count($selected)));

        return self::SUCCESS;
    }
}
