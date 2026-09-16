<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Exception\SitemapImportException;
use OliverThiele\OtWebsitecheck\Service\BasicAuthResolver;
use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Stores the sitemaps of every language of a site as one snapshot, including
 * the raw XML, so the state can be compared later — also when the site itself
 * no longer delivers it, e.g. after a relaunch.
 *
 * Unlike the backend module, the command accepts any URL, not only the bases
 * of the configured sites.
 */
class ImportSitemapsCommand extends Command
{
    use CommandInputTrait;

    public function __construct(
        private readonly SitemapSnapshotImporter $sitemapSnapshotImporter,
        private readonly SiteBaseProvider $siteBaseProvider,
        private readonly BasicAuthResolver $basicAuthResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Store the sitemaps of every language of a site as one snapshot.');
        $this->addArgument('startUrl', InputArgument::OPTIONAL, 'Start page of the site, e.g. "https://www.example.com/". Its hreflang links name the languages. Not needed with --sitemap.');
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'Unique name of the snapshot, e.g. "live-before-relaunch". Defaults to host and time.', '');
        $this->addOption('note', null, InputOption::VALUE_REQUIRED, 'Free text stored with the snapshot, e.g. what is known to be missing.', '');
        $this->addOption('sitemap', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sitemap of one language as "hreflang=url", e.g. "de-DE=https://www.example.com/de/sitemap.xml". Replaces the detection from the start page.');
        $this->addOption('sitemap-path', null, InputOption::VALUE_REQUIRED, 'Sitemap path below the home page of each language, e.g. "sitemap.xml" or "?type=1533906435". Defaults to the path configured for the site of the start URL.');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds.', '20');
        $this->addOption('basic-auth', null, InputOption::VALUE_REQUIRED, 'HTTP Basic Auth credentials as "user:password".');
        $this->addOption('basic-auth-env', null, InputOption::VALUE_REQUIRED, 'Prefix of the environment variables holding the Basic Auth credentials, read as <prefix>_USER and <prefix>_PASS.', 'WEBSITECHECK_BASIC_AUTH');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $startUrl = $this->stringValue($input->getArgument('startUrl'));
        $timeout = max(1, $this->intValue($input->getOption('timeout'), 20));
        $fetchedAt = time();

        $requestOptions = $this->basicAuthResolver->buildRequestOptions(
            $this->stringValue($input->getOption('basic-auth')),
            $this->stringValue($input->getOption('basic-auth-env')),
        );

        $sitemaps = $this->parseSitemapOptions($this->stringList($input->getOption('sitemap')));
        if ($sitemaps === null) {
            $io->error('--sitemap must be in the form "hreflang=url", e.g. --sitemap=de-DE=https://www.example.com/de/sitemap.xml');
            return self::FAILURE;
        }
        if ($sitemaps === []) {
            if ($startUrl === '') {
                $io->error('Either a start URL or at least one --sitemap is required.');
                return self::FAILURE;
            }
            $sitemapPath = $this->stringValue($input->getOption('sitemap-path'));
            if ($sitemapPath === '') {
                $sitemapPath = $this->siteBaseProvider->resolveSitemapPathForUrl($startUrl);
            }
            $sitemaps = $this->sitemapSnapshotImporter->discoverSitemaps($startUrl, $sitemapPath, $timeout, $requestOptions);
            if (array_keys($sitemaps) === ['']) {
                $io->warning('The start page lists no hreflang links (or did not answer with 200). Importing a single sitemap without a language.');
            }
        }
        if ($startUrl === '') {
            $startUrl = (string)reset($sitemaps);
        }

        try {
            $snapshotUid = $this->sitemapSnapshotImporter->startSnapshot(
                $this->stringValue($input->getOption('label')),
                $startUrl,
                $this->stringValue($input->getOption('note')),
                $fetchedAt,
            );
        } catch (SitemapImportException $exception) {
            $io->error($exception->getMessage());
            return self::FAILURE;
        }

        $io->title(sprintf('Sitemap snapshot for %s', $startUrl));

        $tableRows = [];
        $failedDocuments = [];
        foreach ($sitemaps as $language => $sitemapUrl) {
            $io->writeln(sprintf('Fetching %s: <info>%s</info>', $language !== '' ? $language : '(no language)', $sitemapUrl));
            $result = $this->sitemapSnapshotImporter->importLanguage($snapshotUid, $language, $sitemapUrl, $timeout, $requestOptions);
            foreach ($result->urlCountsByGroup as $group => $count) {
                $tableRows[] = [$language, $group !== '' ? $group : '(none)', (string)$count];
            }
            foreach ($result->failedDocuments as $document) {
                $failedDocuments[] = sprintf('%s: %s (%s, HTTP %d)', $language, $document->url, $document->type, $document->httpStatus);
            }
        }

        if ($tableRows !== []) {
            $io->table(['Language', 'Sitemap group', 'URLs'], $tableRows);
        }
        if ($failedDocuments !== []) {
            $io->warning('Some sitemap files could not be fetched or parsed. They are stored with the snapshot:');
            $io->listing($failedDocuments);
        }

        try {
            $urlCount = $this->sitemapSnapshotImporter->finishSnapshot($snapshotUid);
        } catch (SitemapImportException $exception) {
            $io->error($exception->getMessage());
            return self::FAILURE;
        }
        $io->success(sprintf('Stored the snapshot with %d URLs.', $urlCount));

        return self::SUCCESS;
    }

    /**
     * @param list<string> $values
     * @return array<string, string>|null hreflang => sitemap URL, or null if a value is malformed.
     */
    private function parseSitemapOptions(array $values): ?array
    {
        $sitemaps = [];
        foreach ($values as $value) {
            $separatorPosition = strpos($value, '=');
            if ($separatorPosition === false || $separatorPosition === 0) {
                return null;
            }
            $url = substr($value, $separatorPosition + 1);
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                return null;
            }
            $sitemaps[substr($value, 0, $separatorPosition)] = $url;
        }

        return $sitemaps;
    }
}
