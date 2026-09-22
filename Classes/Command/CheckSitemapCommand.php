<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use OliverThiele\OtWebsitecheck\Service\BasicAuthResolver;
use OliverThiele\OtWebsitecheck\Service\ErrorMarkerDetector;
use OliverThiele\OtWebsitecheck\Service\PageFetcher;
use OliverThiele\OtWebsitecheck\Service\PageUidResolver;
use OliverThiele\OtWebsitecheck\Service\RetryRounds;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotLocator;
use OliverThiele\OtWebsitecheck\Service\UrlHostRewriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Requests every URL of a sitemap snapshot and records the HTTP status and any
 * TYPO3 error marker found in the response body, so results from different
 * environments and runs can be compared and reviewed in the backend module.
 */
class CheckSitemapCommand extends Command
{
    use CommandInputTrait;

    public function __construct(
        private readonly SitemapSnapshotLocator $sitemapSnapshotLocator,
        private readonly PageFetcher $pageFetcher,
        private readonly ErrorMarkerDetector $errorMarkerDetector,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly PageUidResolver $pageUidResolver,
        private readonly BasicAuthResolver $basicAuthResolver,
        private readonly UrlHostRewriter $urlHostRewriter,
        private readonly RetryRounds $retryRounds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Request every URL of a sitemap snapshot and record the HTTP status and error markers.');
        $this->addOption('snapshot', null, InputOption::VALUE_REQUIRED, 'Label of the sitemap snapshot whose URLs are checked.');
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'Label for the checked environment, e.g. "staging" or "live". Defaults to the environment of the snapshot, unless --host is given.');
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Request the paths of the snapshot on this host instead, e.g. "www.example.com" — to check a URL list collected on one environment against another one.');
        $this->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only check URLs from these sitemap groups, e.g. "pages".');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds.', '10');
        $this->addOption('retries', null, InputOption::VALUE_REQUIRED, 'How often a URL that timed out or got no connection is requested again. Retries run after all other URLs.', '2');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only check the first N URLs (for a quick test run).');
        $this->addOption('basic-auth', null, InputOption::VALUE_REQUIRED, 'HTTP Basic Auth credentials as "user:password", for environments protected at the webserver level.');
        $this->addOption('basic-auth-env', null, InputOption::VALUE_REQUIRED, 'Prefix of the environment variables holding the Basic Auth credentials, read as <prefix>_USER and <prefix>_PASS.', 'WEBSITECHECK_BASIC_AUTH');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $snapshot = $this->sitemapSnapshotLocator->findCompleteSnapshot($this->stringValue($input->getOption('snapshot')));
        } catch (\InvalidArgumentException $exception) {
            $io->error('--snapshot: ' . $exception->getMessage());
            return self::FAILURE;
        }
        $environment = $this->stringValue($input->getOption('environment'));
        // The tag describes the host of the snapshot; with --host another one is requested.
        if ($environment === '' && $snapshot->environment !== '' && $this->stringValue($input->getOption('host')) === '') {
            $environment = $snapshot->environment;
            $io->note(sprintf('--environment taken from the snapshot: "%s".', $environment));
        }
        if ($environment === '') {
            $io->error($snapshot->environment !== ''
                ? 'With --host, the --environment option is required, e.g. --environment=staging'
                : 'The --environment option is required unless the snapshot has an environment, e.g. --environment=staging');
            return self::FAILURE;
        }

        $timeout = max(1, $this->intValue($input->getOption('timeout'), 10));
        $retries = max(0, $this->intValue($input->getOption('retries'), 2));
        $limit = $this->intValue($input->getOption('limit'), 0);
        $host = $this->stringValue($input->getOption('host'));
        $requestOptions = $this->basicAuthResolver->buildRequestOptions(
            $this->stringValue($input->getOption('basic-auth')),
            $this->stringValue($input->getOption('basic-auth-env')),
        );

        $io->title(sprintf('Website Check: snapshot "%s" (%s)', $snapshot->label, $environment));

        $urls = array_keys($this->sitemapSnapshotLocator->findUrls($snapshot, $this->stringList($input->getOption('group'))));
        if ($host !== '') {
            $urls = array_map(fn(string $url): string => $this->urlHostRewriter->replace($url, $host), $urls);
            $io->writeln(sprintf('Requesting every path on host "%s".', $host));
        }
        if ($limit > 0) {
            $urls = array_slice($urls, 0, $limit);
        }
        if ($urls === []) {
            $io->error(sprintf('The snapshot "%s" has no URLs in the selected groups.', $snapshot->label));
            return self::FAILURE;
        }

        $io->writeln(sprintf('Checking %d URLs against "%s"...', count($urls), $environment));

        $notOkCount = 0;
        $errorMarkerCount = 0;
        $timeoutCount = 0;
        $this->retryRounds->run(
            $urls,
            $retries,
            function (string $url) use ($timeout, $requestOptions, $io): FetchedPage {
                $page = $this->pageFetcher->fetch($url, $timeout, $requestOptions);
                $io->progressAdvance();
                return $page;
            },
            static fn(FetchedPage $page): bool => $page->isRetryable(),
            function (string $url, FetchedPage $page) use ($environment, $snapshot, &$notOkCount, &$errorMarkerCount, &$timeoutCount): void {
                $errorMarker = $this->errorMarkerDetector->detectFor($page);
                if (!$page->isOk()) {
                    $notOkCount++;
                }
                if ($page->isTimeout()) {
                    $timeoutCount++;
                } elseif ($errorMarker !== '') {
                    $errorMarkerCount++;
                }

                $this->checkResultRepository->storeResult($url, $environment, $snapshot->label, $this->pageUidResolver->resolve($url), $page->httpStatus, $errorMarker, time());
            },
            static function (int $round, int $count) use ($io): void {
                if ($round > 1) {
                    $io->progressFinish();
                    $io->writeln(sprintf('Retrying %d URLs that timed out or got no connection (round %d)...', $count, $round));
                }
                $io->progressStart($count);
            },
        );

        $io->progressFinish();
        $io->writeln(sprintf(
            '%d URLs checked, %d without HTTP 200, %d with a detected error marker, %d timed out.',
            count($urls),
            $notOkCount,
            $errorMarkerCount,
            $timeoutCount,
        ));

        return self::SUCCESS;
    }
}
