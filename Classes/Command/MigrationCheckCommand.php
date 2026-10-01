<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\IdentityPatterns;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Service\BasicAuthResolver;
use OliverThiele\OtWebsitecheck\Service\IdentityExtractor;
use OliverThiele\OtWebsitecheck\Service\MigrationAnalyzer;
use OliverThiele\OtWebsitecheck\Service\RedirectChainFollower;
use OliverThiele\OtWebsitecheck\Service\RetryRounds;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotLocator;
use OliverThiele\OtWebsitecheck\Service\UrlHostRewriter;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks whether the URLs of a reference environment (usually live) still lead
 * to the same content on a target environment (e.g. a relaunch on staging):
 * directly, through a redirect, or not at all.
 *
 * Only HTTP answers are evaluated, so it does not matter whether a redirect is
 * configured in the webserver or in TYPO3.
 *
 * Which URLs are checked comes from stored sitemap snapshots: the reference
 * snapshot is the state before, the target snapshot the state after the
 * migration. The target host is the host of the target snapshot.
 */
class MigrationCheckCommand extends Command
{
    use CommandInputTrait;

    public function __construct(
        private readonly SitemapSnapshotLocator $sitemapSnapshotLocator,
        private readonly MigrationRunRepository $migrationRunRepository,
        private readonly RedirectChainFollower $redirectChainFollower,
        private readonly IdentityExtractor $identityExtractor,
        private readonly ObservationRepository $observationRepository,
        private readonly MigrationAnalyzer $migrationAnalyzer,
        private readonly BasicAuthResolver $basicAuthResolver,
        private readonly UrlHostRewriter $urlHostRewriter,
        private readonly RetryRounds $retryRounds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Check whether the URLs of a reference environment still lead to the same content on a target environment.');
        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Label that groups the results of this check, e.g. "relaunch". Re-running with the same label replaces its rows; review states of unchanged findings are kept.');
        $this->addOption('reference-snapshot', null, InputOption::VALUE_REQUIRED, 'Label of the sitemap snapshot of the reference environment (the state before). Every URL in it is checked.');
        $this->addOption('target-snapshot', null, InputOption::VALUE_REQUIRED, 'Label of the sitemap snapshot of the target environment (the state after). Its host is where the reference paths are requested; its pages are read to suggest where a missing URL should redirect to.');
        $this->addOption('reference-label', null, InputOption::VALUE_REQUIRED, 'Environment label of the reference rows.', 'reference');
        $this->addOption('target-label', null, InputOption::VALUE_REQUIRED, 'Environment label of the target rows.', 'target');
        $this->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only check URLs from these sitemap groups, e.g. "pages".');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only check the first N reference URLs (for a quick test run).');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds.', '10');
        $this->addOption('retries', null, InputOption::VALUE_REQUIRED, 'How often a URL that timed out or got no connection is requested again. Retries run after all other URLs.', '2');
        $this->addOption('max-hops', null, InputOption::VALUE_REQUIRED, 'Maximum number of redirects followed per URL.', '10');
        $this->addOption('page-uid-pattern', null, InputOption::VALUE_REQUIRED, 'Regular expression reading the page uid (capture group 1) from the HTML.', IdentityPatterns::DEFAULT_PAGE_UID);
        $this->addOption('language-pattern', null, InputOption::VALUE_REQUIRED, 'Regular expression reading the language (capture group 1) from the HTML.', IdentityPatterns::DEFAULT_LANGUAGE);
        $this->addOption('record-pattern', null, InputOption::VALUE_REQUIRED, 'Regular expression reading the record table (group 1) and uid (group 2) from the HTML of a detail page.', IdentityPatterns::DEFAULT_RECORD);
        $this->addOption('reference-run', null, InputOption::VALUE_REQUIRED, 'Take the reference rows from this earlier run instead of requesting the reference again, e.g. after the reference site has been replaced. The run must have compared the same reference snapshot; may equal --run.');
        $this->addOption('analyze-only', null, InputOption::VALUE_NONE, 'Do not request anything; recompute verdicts, warnings and suggestions for the stored rows of --run.');
        $this->addOption('fail-on-problems', null, InputOption::VALUE_NONE, 'Exit with a failure code when a target row has a verdict that needs attention (missing, redirectBroken, otherContent, identityUnknown, timeout) — for CI. A run in which no target URL answered at all fails without this option, too.');
        $this->addOption('reference-basic-auth', null, InputOption::VALUE_REQUIRED, 'HTTP Basic Auth for the reference environment as "user:password". Falls back to WEBSITECHECK_REFERENCE_BASIC_AUTH_USER/_PASS.');
        $this->addOption('target-basic-auth', null, InputOption::VALUE_REQUIRED, 'HTTP Basic Auth for the target environment as "user:password". Falls back to WEBSITECHECK_TARGET_BASIC_AUTH_USER/_PASS.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $runLabel = $this->stringValue($input->getOption('run'));
        $referenceLabel = $this->stringValue($input->getOption('reference-label'));
        $targetLabel = $this->stringValue($input->getOption('target-label'));

        if ($input->getOption('analyze-only') === true) {
            if ($runLabel === '') {
                $io->error('--run is required.');
                return self::FAILURE;
            }
            $verdictCounts = $this->analyzeRun($runLabel);
            $this->renderVerdictCounts($io, $verdictCounts, $runLabel);
            return $this->exitCode($input, $verdictCounts);
        }

        if (mb_strlen($runLabel) > MigrationRunRepository::MAXIMUM_LABEL_LENGTH) {
            $io->error(sprintf('--run must not be longer than %d characters.', MigrationRunRepository::MAXIMUM_LABEL_LENGTH));
            return self::FAILURE;
        }
        if ($runLabel === '' || $referenceLabel === '' || $targetLabel === '' || $referenceLabel === $targetLabel) {
            $io->error('--run is required, and --reference-label and --target-label must differ.');
            return self::FAILURE;
        }
        try {
            $referenceSnapshot = $this->sitemapSnapshotLocator->findCompleteSnapshot($this->stringValue($input->getOption('reference-snapshot')));
        } catch (\InvalidArgumentException $exception) {
            $io->error('--reference-snapshot: ' . $exception->getMessage());
            return self::FAILURE;
        }
        try {
            $targetSnapshot = $this->sitemapSnapshotLocator->findCompleteSnapshot($this->stringValue($input->getOption('target-snapshot')));
        } catch (\InvalidArgumentException $exception) {
            $io->error('--target-snapshot: ' . $exception->getMessage());
            return self::FAILURE;
        }
        $targetHost = parse_url($targetSnapshot->startUrl, PHP_URL_HOST);
        if (!is_string($targetHost) || $targetHost === '') {
            $io->error(sprintf('The start URL of the target snapshot "%s" has no host.', $targetSnapshot->label));
            return self::FAILURE;
        }
        $targetSitemapLabel = $targetLabel . '-sitemap';

        $patterns = new IdentityPatterns(
            $this->stringValue($input->getOption('page-uid-pattern')),
            $this->stringValue($input->getOption('language-pattern')),
            $this->stringValue($input->getOption('record-pattern')),
        );
        $invalidPatterns = $patterns->findInvalidPatterns();
        if ($invalidPatterns !== []) {
            $io->error(sprintf('Invalid regular expression(s): %s', implode(', ', $invalidPatterns)));
            return self::FAILURE;
        }

        $timeout = max(1, $this->intValue($input->getOption('timeout'), 10));
        $maximumHops = max(1, $this->intValue($input->getOption('max-hops'), 10));
        $retries = max(0, $this->intValue($input->getOption('retries'), 2));
        $limit = $this->intValue($input->getOption('limit'), 0);
        $groups = $this->stringList($input->getOption('group'));

        $referenceRequestOptions = $this->basicAuthResolver->buildRequestOptions(
            $this->stringValue($input->getOption('reference-basic-auth')),
            'WEBSITECHECK_REFERENCE_BASIC_AUTH',
        );
        $targetRequestOptions = $this->basicAuthResolver->buildRequestOptions(
            $this->stringValue($input->getOption('target-basic-auth')),
            'WEBSITECHECK_TARGET_BASIC_AUTH',
        );

        $referenceRunLabel = $this->stringValue($input->getOption('reference-run'));
        $referenceRows = [];
        if ($referenceRunLabel !== '') {
            $referenceRun = $this->migrationRunRepository->findByLabel($referenceRunLabel);
            if ($referenceRun === null) {
                $io->error(sprintf('--reference-run: there is no run named "%s".', $referenceRunLabel));
                return self::FAILURE;
            }
            if ($referenceRun['referenceSnapshotUid'] !== $referenceSnapshot->uid) {
                $io->error(sprintf('--reference-run: run "%s" did not compare the reference snapshot "%s".', $referenceRunLabel, $referenceSnapshot->label));
                return self::FAILURE;
            }
            $referenceRows = $this->observationRepository->findRowsByRun($referenceRunLabel, Observation::ROLE_REFERENCE);
            // The copied rows keep their environment label; a target row with the same label would replace them.
            $clashingLabels = array_intersect(
                array_unique(array_map(static fn(array $row): string => (string)$row['environment'], $referenceRows)),
                [$targetLabel, $targetSitemapLabel],
            );
            if ($clashingLabels !== []) {
                $io->error(sprintf('--reference-run: its reference rows use the environment label "%s"; choose a different --target-label.', implode('", "', $clashingLabels)));
                return self::FAILURE;
            }
        }

        $io->title(sprintf('Migration check "%s": "%s" → "%s" (%s)', $runLabel, $referenceSnapshot->label, $targetSnapshot->label, $targetHost));

        $referenceUrls = $this->sitemapSnapshotLocator->findUrls($referenceSnapshot, $groups);
        if ($limit > 0) {
            $referenceUrls = array_slice($referenceUrls, 0, $limit, true);
        }
        if ($referenceUrls === []) {
            $io->error(sprintf('The reference snapshot "%s" has no URLs in the selected groups.', $referenceSnapshot->label));
            return self::FAILURE;
        }
        if ($referenceRunLabel !== '') {
            $storedReferenceUrls = $this->takeOverReferenceRows($referenceRows, $referenceRunLabel === $runLabel, $runLabel, $referenceUrls);
            $skippedCount = count($referenceUrls) - count($storedReferenceUrls);
            if ($skippedCount > 0) {
                $io->note(sprintf('%d reference URLs have no row in run "%s" and are skipped.', $skippedCount, $referenceRunLabel));
            }
            $referenceUrls = array_intersect_key($referenceUrls, $storedReferenceUrls);
            if ($referenceUrls === []) {
                $io->error(sprintf('Run "%s" has no reference rows for the selected URLs.', $referenceRunLabel));
                return self::FAILURE;
            }
        }
        $targetSnapshotUrls = $this->sitemapSnapshotLocator->findUrls($targetSnapshot, $groups);
        foreach (['reference' => [$referenceSnapshot, $referenceUrls], 'target' => [$targetSnapshot, $targetSnapshotUrls]] as $side => [$snapshot, $urls]) {
            $collisions = $this->findPathCollisions(array_keys($urls));
            if ($collisions !== []) {
                $io->error(sprintf(
                    'The %s snapshot "%s" lists the same path on more than one host or scheme. A migration check matches reference and target by path, so these URLs would overwrite each other. Import one snapshot per host, or narrow the selection with --group.',
                    $side,
                    $snapshot->label,
                ));
                $io->listing(array_map(
                    static fn(string $path, array $collidingUrls): string => $path . ': ' . implode(', ', $collidingUrls),
                    array_keys(array_slice($collisions, 0, 5, true)),
                    array_slice($collisions, 0, 5, true),
                ));
                return self::FAILURE;
            }
        }
        $this->migrationRunRepository->storeRun($runLabel, $referenceSnapshot->uid, $targetSnapshot->uid, $targetHost, time());

        $io->section(sprintf(
            $referenceRunLabel !== '' ? 'Checking %d URLs on the target, reference rows taken from run "%s"' : 'Checking %d reference URLs on both environments',
            count($referenceUrls),
            $referenceRunLabel,
        ));
        $referenceAuthorizedUrls = $this->sitemapSnapshotLocator->findAuthorizedUrls($referenceSnapshot);
        $targetAuthorizedUrls = $this->sitemapSnapshotLocator->findAuthorizedUrls($targetSnapshot);
        $observations = [];
        /** @var array<string, bool> $checkedTargetUrls */
        $checkedTargetUrls = [];
        foreach ($referenceUrls as $referenceUrl => $group) {
            if ($referenceRunLabel === '') {
                $observations[] = [
                    'environment' => $referenceLabel,
                    'role' => Observation::ROLE_REFERENCE,
                    'group' => $group,
                    'url' => $referenceUrl,
                    'requestOptions' => UrlUtility::requestOptionsFor($referenceRequestOptions, $referenceUrl, $referenceAuthorizedUrls),
                ];
            }

            $targetUrl = $this->urlHostRewriter->replace($referenceUrl, $targetHost);
            $observations[] = [
                'environment' => $targetLabel,
                'role' => Observation::ROLE_TARGET,
                'group' => $group,
                'url' => $targetUrl,
                'requestOptions' => UrlUtility::requestOptionsFor($targetRequestOptions, $targetUrl, $targetAuthorizedUrls),
            ];
            $checkedTargetUrls[$targetUrl] = true;
        }

        $targetUrls = array_diff_key($targetSnapshotUrls, $checkedTargetUrls);
        $sitemapObservations = [];
        foreach ($targetUrls as $targetUrl => $group) {
            $sitemapObservations[] = [
                'environment' => $targetSitemapLabel,
                'role' => Observation::ROLE_TARGET_SITEMAP,
                'group' => $group,
                'url' => $targetUrl,
                'requestOptions' => UrlUtility::requestOptionsFor($targetRequestOptions, $targetUrl, $targetAuthorizedUrls),
            ];
        }

        // A re-run with another selection, other labels or changed snapshots
        // must not leave rows of the earlier one behind: the analysis reads all
        // rows of the run. Rows the run produces again stay, with their review.
        $keptRows = [];
        foreach ([...$observations, ...$sitemapObservations] as $observation) {
            $keptRows[$observation['environment']][UrlUtility::pathWithQuery($observation['url'])] = true;
        }
        foreach ($referenceRows as $row) {
            if (isset($referenceUrls[(string)$row['requested_url']])) {
                $keptRows[(string)$row['environment']][(string)$row['requested_path']] = true;
            }
        }
        $removedCount = $this->observationRepository->deleteRowsOfRunExcept($runLabel, $keptRows);
        if ($removedCount > 0) {
            $io->note(sprintf('Removed %d rows of an earlier run "%s" that this selection no longer covers.', $removedCount, $runLabel));
        }

        $answeredCount = $this->observeAll($io, $runLabel, $observations, $timeout, $maximumHops, $retries, $patterns);

        $io->section(sprintf('Reading %d further pages from the target snapshot', count($targetUrls)));
        $answeredCount += $this->observeAll($io, $runLabel, $sitemapObservations, $timeout, $maximumHops, $retries, $patterns);

        $verdictCounts = $this->analyzeRun($runLabel);
        $this->renderVerdictCounts($io, $verdictCounts, $runLabel);
        if ($answeredCount === 0) {
            $io->error('Not a single URL answered. Check the hosts, the network and the Basic Auth credentials.');
            return self::FAILURE;
        }

        return $this->exitCode($input, $verdictCounts);
    }

    /**
     * @param array<string, int> $verdictCounts
     */
    private function exitCode(InputInterface $input, array $verdictCounts): int
    {
        if ($input->getOption('fail-on-problems') !== true) {
            return self::SUCCESS;
        }
        $problemCount = array_sum(array_intersect_key($verdictCounts, array_flip(MigrationAnalyzer::PROBLEM_VERDICTS)));

        return $problemCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Paths that more than one URL leads to — the same path on two hosts or
     * over http and https. Rows of a run are keyed by path, so such URLs would
     * replace each other.
     *
     * @param list<string> $urls
     * @return array<string, list<string>> path => the URLs sharing it
     */
    private function findPathCollisions(array $urls): array
    {
        $urlsByPath = [];
        foreach ($urls as $url) {
            $urlsByPath[UrlUtility::pathWithQuery($url)][] = $url;
        }

        return array_filter($urlsByPath, static fn(array $urlsOfPath): bool => count($urlsOfPath) > 1);
    }

    /**
     * Copies the reference rows of the given URLs from an earlier run. Their
     * verdicts are recomputed with the new target rows, and the review state
     * of the earlier run is not carried over.
     *
     * @param list<array<string, int|string>> $referenceRows reference rows of the earlier run
     * @param bool $sameRun the earlier run is the current one; its rows stay as they are
     * @param array<string, string> $referenceUrls reference URL => sitemap group
     * @return array<string, true> the reference URLs a row exists for
     */
    private function takeOverReferenceRows(array $referenceRows, bool $sameRun, string $runLabel, array $referenceUrls): array
    {
        $found = [];
        foreach ($referenceRows as $row) {
            $requestedUrl = (string)$row['requested_url'];
            if (!isset($referenceUrls[$requestedUrl])) {
                continue;
            }
            $found[$requestedUrl] = true;
            if ($sameRun) {
                continue;
            }
            $this->observationRepository->storeRow($runLabel, [
                'verdict' => '',
                'warnings' => '',
                'suggested_target' => '',
                'reviewed' => 0,
                'note' => '',
            ] + $row);
        }

        return $found;
    }

    /**
     * @param array<string, int> $verdictCounts
     */
    private function renderVerdictCounts(SymfonyStyle $io, array $verdictCounts, string $runLabel): void
    {
        if ($verdictCounts === []) {
            $io->warning(sprintf('Run "%s" has no target rows.', $runLabel));
            return;
        }
        ksort($verdictCounts);
        $io->table(
            ['Verdict of the target rows', 'Count'],
            array_map(static fn(string $verdict, int $count): array => [$verdict, (string)$count], array_keys($verdictCounts), $verdictCounts),
        );
        $io->writeln('Review the results in the backend module Sites > Website Check > Migration check.');
    }

    /**
     * Follows every URL and stores what it leads to. A chain that ended in a
     * timeout or without a connection is followed again after all others, and
     * only its last outcome is stored.
     *
     * @param list<array{environment: string, role: string, group: string, url: string, requestOptions: array<string, mixed>}> $observations
     * @return int how many URLs got an HTTP answer on their first request
     */
    private function observeAll(
        SymfonyStyle $io,
        string $runLabel,
        array $observations,
        int $timeout,
        int $maximumHops,
        int $retries,
        IdentityPatterns $patterns,
    ): int {
        $answeredCount = 0;
        $this->retryRounds->run(
            $observations,
            $retries,
            function (array $observation) use ($timeout, $maximumHops, $io): RedirectChain {
                $redirectChain = $this->redirectChainFollower->follow($observation['url'], $timeout, $maximumHops, $observation['requestOptions']);
                $io->progressAdvance();
                return $redirectChain;
            },
            static fn(RedirectChain $redirectChain): bool => $redirectChain->isRetryable(),
            function (array $observation, RedirectChain $redirectChain) use ($runLabel, $patterns, &$answeredCount): void {
                if ($redirectChain->getFirstStatus() > 0) {
                    $answeredCount++;
                }
                // An error page renders its own page uid — only a working page has an identity worth comparing.
                $identity = $redirectChain->getFinalStatus() === 200
                    ? $this->identityExtractor->extract($redirectChain->finalBody, $patterns)
                    : new PageIdentity();

                $this->observationRepository->storeObservation($runLabel, $observation['environment'], $observation['role'], $observation['group'], $redirectChain, $identity, time());
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

        return $answeredCount;
    }

    /**
     * @return array<string, int> Number of target rows per verdict.
     */
    private function analyzeRun(string $runLabel): array
    {
        $observations = $this->observationRepository->findByRun($runLabel);
        $results = $this->migrationAnalyzer->analyze($observations);

        $verdictCounts = [];
        foreach ($observations as $observation) {
            $result = $results[$observation->uid] ?? null;
            if ($result === null) {
                continue;
            }
            $this->observationRepository->updateAnalysis($observation, $result['verdict'], $result['warnings'], $result['suggestedTarget']);
            if ($observation->role === Observation::ROLE_TARGET) {
                $verdictCounts[$result['verdict']] = ($verdictCounts[$result['verdict']] ?? 0) + 1;
            }
        }

        return $verdictCounts;
    }
}
