<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchive;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchiveImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads an archive written by websitecheck:exportsnapshots into this
 * database. Records that are here already are skipped, so the same archive
 * can be imported again after every database replacement.
 */
class ImportSnapshotsCommand extends Command
{
    use CommandInputTrait;

    public function __construct(
        private readonly SnapshotArchiveImporter $snapshotArchiveImporter,
        private readonly SnapshotArchive $snapshotArchive,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Read sitemap snapshots and migration check runs from an archive file.');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path of the archive file written by websitecheck:exportsnapshots.');
        $this->addOption('label-suffix', null, InputOption::VALUE_REQUIRED, 'Appended to the label of every imported snapshot or run whose label is taken by a different record, e.g. "-restored".', '');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be imported, write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $this->stringValue($input->getOption('file'));
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            $io->error('--file must name a readable archive file.');
            return self::FAILURE;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            $io->error(sprintf('"%s" could not be read.', $file));
            return self::FAILURE;
        }

        try {
            $archive = $this->snapshotArchive->decode($content);
            $plan = $this->snapshotArchiveImporter->plan($archive, $this->stringValue($input->getOption('label-suffix')));
        } catch (SnapshotArchiveException $exception) {
            $io->error($exception->getMessage());
            return self::FAILURE;
        }

        $rows = [];
        foreach (['snapshots' => 'snapshot', 'runs' => 'run'] as $key => $type) {
            foreach ($plan[$key] as $item) {
                $label = $item['label'] === $item['originalLabel'] ? $item['label'] : sprintf('%s (as "%s")', $item['originalLabel'], $item['label']);
                $rows[] = [$type, $label, $item['action'] === SnapshotArchiveImporter::ACTION_IMPORT ? 'import' : 'skip, already here'];
            }
        }
        $io->table(['Type', 'Label', 'Action'], $rows);

        if ($input->getOption('dry-run') === true) {
            $io->note('Dry run: nothing was written.');
            return self::SUCCESS;
        }

        $this->snapshotArchiveImporter->import($archive, $plan);
        $io->success(sprintf('Imported %s.', $file));

        return self::SUCCESS;
    }
}
