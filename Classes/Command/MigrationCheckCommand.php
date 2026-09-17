<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\IdentityPatterns;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Service\BasicAuthResolver;
use OliverThiele\OtWebsitecheck\Service\IdentityExtractor;
use OliverThiele\OtWebsitecheck\Service\MigrationAnalyzer;
use OliverThiele\OtWebsitecheck\Service\RedirectChainFollower;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotLocator;
use OliverThiele\OtWebsitecheck\Service\UrlHostRewriter;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Check whether the URLs of a reference environment still lead to the same content on a target environment.');
        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Label that groups the results of this check, e.g. "relaunch". Re-running with the same label updates the rows.');
        $this->addOption('reference-snapshot', null, InputOption::VALUE_REQUIRED, 'Label of the sitemap snapshot of the reference environment (the state before). Every URL in it is checked.');
        $this->addOption('target-snapshot', null, InputOption::VALUE_REQUIRED, 'Label of the sitemap snapshot of the target environment (the state after). Its host is where the reference paths are requested; its pages are read to suggest where a missing URL should redirect to.');
        $this->addOption('reference-label', null, InputOption::VALUE_REQUIRED, 'Environment label of the reference rows.', 'reference');
        $this->addOption('target-label', null, InputOption::VALUE_REQUIRED, 'Environment label of the target rows.', 'target');
        $this->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only check URLs from these sitemap groups, e.g. "pages".');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only check the first N reference URLs (for a quick test run).');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds.', '10');
        $this->addOption('max-hops', null, InputOption::VALUE_REQUIRED, 'Maximum number of redirects followed per URL.', '10');
        $this->addOption('page-uid-pattern', null, InputOption::VALUE_REQUIRED, 'Regular expression reading the page uid (capture group 1) from the HTML.', IdentityPatterns::DEFAULT_PAGE_UID);
        $this->addOption('language-pattern', null, InputOption::VALUE_REQUIRED, 'Regular expression reading the language (capture group 1) from the HTML.', IdentityPatterns::DEFAULT_LANGUAGE);
        $this->addOption('record-pattern', null, InputOption::VALUE_REQUIRED, 'Regular expression reading the record table (group 1) and uid (group 2) from the HTML of a detail page.', IdentityPatterns::DEFAULT_RECORD);
        $this->addOption('reference-run', null, InputOption::VALUE_REQUIRED, 'Take the reference rows from this earlier run instead of requesting the reference again, e.g. after the reference site has been replaced. The run must have compared the same reference snapshot; may equal --run.');
        $this->addOption('analyze-only', null, InputOption::VALUE_NONE, 'Do not request anything; recompute verdicts, warnings and suggestions for the stored rows of --run.');
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
            $this->renderVerdictCounts($io, $this->analyzeRun($runLabel), $runLabel);
            return self::SUCCESS;
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
        $this->migrationRunRepository->storeRun($runLabel, $referenceSnapshot->uid, $targetSnapshot->uid, $targetHost, time());

        $io->section(sprintf(
            $referenceRunLabel !== '' ? 'Checking %d URLs on the target, reference rows taken from run "%s"' : 'Checking %d reference URLs on both environments',
            count($referenceUrls),
            $referenceRunLabel,
        ));
        $io->progressStart(count($referenceUrls));
        /** @var array<string, bool> $checkedTargetUrls */
        $checkedTargetUrls = [];
        foreach ($referenceUrls as $referenceUrl => $group) {
            if ($referenceRunLabel === '') {
                $this->observe($runLabel, $referenceLabel, Observation::ROLE_REFERENCE, $group, $referenceUrl, $timeout, $maximumHops, $referenceRequestOptions, $patterns);
            }

            $targetUrl = $this->urlHostRewriter->replace($referenceUrl, $targetHost);
            $this->observe($runLabel, $targetLabel, Observation::ROLE_TARGET, $group, $targetUrl, $timeout, $maximumHops, $targetRequestOptions, $patterns);
            $checkedTargetUrls[$targetUrl] = true;

            $io->progressAdvance();
        }
        $io->progressFinish();

        $targetUrls = array_diff_key(
            $this->sitemapSnapshotLocator->findUrls($targetSnapshot, $groups),
            $checkedTargetUrls,
        );
        $io->section(sprintf('Reading %d further pages from the target snapshot', count($targetUrls)));
        $io->progressStart(count($targetUrls));
        foreach ($targetUrls as $targetUrl => $group) {
            $this->observe($runLabel, $targetSitemapLabel, Observation::ROLE_TARGET_SITEMAP, $group, $targetUrl, $timeout, $maximumHops, $targetRequestOptions, $patterns);
            $io->progressAdvance();
        }
        $io->progressFinish();

        $this->renderVerdictCounts($io, $this->analyzeRun($runLabel), $runLabel);

        return self::SUCCESS;
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
     * @param array<string, mixed> $requestOptions
     */
    private function observe(
        string $runLabel,
        string $environment,
        string $role,
        string $group,
        string $url,
        int $timeout,
        int $maximumHops,
        array $requestOptions,
        IdentityPatterns $patterns,
    ): void {
        $redirectChain = $this->redirectChainFollower->follow($url, $timeout, $maximumHops, $requestOptions);
        // An error page renders its own page uid — only a working page has an identity worth comparing.
        $identity = $redirectChain->getFinalStatus() === 200
            ? $this->identityExtractor->extract($redirectChain->finalBody, $patterns)
            : new PageIdentity();

        $this->observationRepository->storeObservation($runLabel, $environment, $role, $group, $redirectChain, $identity, time());
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
