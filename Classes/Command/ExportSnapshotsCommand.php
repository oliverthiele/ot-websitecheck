<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchive;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchiveExporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes sitemap snapshots and migration check runs into an archive file, so
 * they survive a database that is replaced — e.g. by an import of the live
 * database — and can be carried to another instance.
 *
 * The path is always given: where such files belong is up to the project.
 */
class ExportSnapshotsCommand extends Command
{
    use CommandInputTrait;

    public function __construct(
        private readonly SnapshotArchiveExporter $snapshotArchiveExporter,
        private readonly SnapshotArchive $snapshotArchive,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Write sitemap snapshots and migration check runs into an archive file.');
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path of the archive file to write, e.g. "var/websitecheck/snapshots.json.gz".');
        $this->addOption('snapshot', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Label of a snapshot to export. Repeatable.');
        $this->addOption('locked', null, InputOption::VALUE_NONE, 'Export every locked snapshot.');
        $this->addOption('run', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Label of a migration check run to export, with its results and the snapshots it compared. Repeatable.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $this->stringValue($input->getOption('file'));
        if ($file === '') {
            $io->error('--file is required.');
            return self::FAILURE;
        }
        if (file_exists($file) && $input->getOption('force') !== true) {
            $io->error(sprintf('"%s" exists already. Use --force to overwrite it.', $file));
            return self::FAILURE;
        }
        $directory = dirname($file);
        if (!is_dir($directory) || !is_writable($directory)) {
            $io->error(sprintf('The directory "%s" does not exist or is not writable.', $directory));
            return self::FAILURE;
        }

        try {
            $result = $this->snapshotArchiveExporter->export(
                $this->stringList($input->getOption('snapshot')),
                $input->getOption('locked') === true,
                $this->stringList($input->getOption('run')),
            );
            $content = $this->snapshotArchive->encode($result['archive']);
        } catch (SnapshotArchiveException $exception) {
            $io->error($exception->getMessage());
            return self::FAILURE;
        }
        foreach ($result['notes'] as $note) {
            $io->note($note);
        }

        if (file_put_contents($file, $content) === false) {
            $io->error(sprintf('"%s" could not be written.', $file));
            return self::FAILURE;
        }

        $archive = $result['archive'];
        $io->table(
            ['Type', 'Label', 'Details'],
            [
                ...array_map(static fn(array $snapshot): array => [
                    'snapshot',
                    $snapshot['label'],
                    sprintf('%d sitemaps%s', count($snapshot['documents']), $snapshot['locked'] ? ', locked' : ''),
                ], $archive['snapshots']),
                ...array_map(static fn(array $run): array => [
                    'run',
                    $run['label'],
                    sprintf('%d result rows', count($run['observations'])),
                ], $archive['runs']),
            ],
        );
        $io->success(sprintf('Wrote %s (%s KB).', $file, number_format(strlen($content) / 1024, 0)));

        return self::SUCCESS;
    }
}
