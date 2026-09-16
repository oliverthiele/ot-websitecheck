<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Command;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use OliverThiele\OtWebsitecheck\Service\BasicAuthResolver;
use OliverThiele\OtWebsitecheck\Service\ErrorMarkerDetector;
use OliverThiele\OtWebsitecheck\Service\PageFetcher;
use OliverThiele\OtWebsitecheck\Service\PageLinkCollector;
use OliverThiele\OtWebsitecheck\Service\PageUidResolver;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Visits the pages of a sitemap snapshot, reads the plugin links out of the rendered
 * HTML and checks those.
 *
 * This exists for a failure mode the sitemap crawl cannot see. When a plugin
 * moves to a different Extbase namespace or to a different page — as happens
 * when a list_type becomes a CType — links built for the old one keep their
 * HTTP 200. The arguments simply arrive nowhere, the plugin falls through to
 * its default action, and the visitor gets a plausible looking wrong page. So
 * every link is checked twice: once as it stands, and once with all arguments
 * removed. If both answers are the same page, the arguments did nothing.
 */
class CrawlLinksCommand extends Command
{
    use CommandInputTrait;

    /**
     * Marker written when a link's arguments turn out to have no effect.
     * Deliberately not one of ErrorMarkerDetector's markers — nothing failed,
     * which is the point.
     */
    private const string MARKER_ARGUMENTS_IGNORED = 'argumentsIgnored';

    public function __construct(
        private readonly SitemapSnapshotLocator $sitemapSnapshotLocator,
        private readonly PageLinkCollector $pageLinkCollector,
        private readonly PageFetcher $pageFetcher,
        private readonly ErrorMarkerDetector $errorMarkerDetector,
        private readonly CheckResultRepository $checkResultRepository,
        private readonly PageUidResolver $pageUidResolver,
        private readonly BasicAuthResolver $basicAuthResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Follow the plugin links on every page of a sitemap snapshot and check where they actually lead.');
        $this->addOption('snapshot', null, InputOption::VALUE_REQUIRED, 'Label of the sitemap snapshot whose pages are the starting points.');
        $this->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only start from pages of these sitemap groups, e.g. "pages".');
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, 'Label for the checked environment, e.g. "ddev-links" or "staging-links".');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'HTTP timeout per request in seconds.', '20');
        $this->addOption('pages-limit', null, InputOption::VALUE_REQUIRED, 'Only read links from the first N sitemap pages.');
        $this->addOption('samples-per-shape', null, InputOption::VALUE_REQUIRED, 'How many links per distinct link shape to check. A shape is the path plus the argument names, so hundreds of links differing only in a record uid collapse into one.', '2');
        $this->addOption('max-links', null, InputOption::VALUE_REQUIRED, 'Upper bound on the number of links checked, as a safety net on a large site.', '2000');
        $this->addOption('all-links', null, InputOption::VALUE_NONE, 'Also follow links without Extbase arguments. Off by default: the sitemap crawl already covers plain pages.');
        $this->addOption('basic-auth', null, InputOption::VALUE_REQUIRED, 'HTTP Basic Auth credentials as "user:password".');
        $this->addOption('basic-auth-env', null, InputOption::VALUE_REQUIRED, 'Prefix of the environment variables holding the Basic Auth credentials, read as <prefix>_USER and <prefix>_PASS.', 'WEBSITECHECK_BASIC_AUTH');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $environment = $this->stringValue($input->getOption('environment'));
        if ($environment === '') {
            $io->error('The --environment option is required, e.g. --environment=ddev-links');
            return self::FAILURE;
        }
        try {
            $snapshot = $this->sitemapSnapshotLocator->findCompleteSnapshot($this->stringValue($input->getOption('snapshot')));
        } catch (\InvalidArgumentException $exception) {
            $io->error('--snapshot: ' . $exception->getMessage());
            return self::FAILURE;
        }

        $timeout = max(1, $this->intValue($input->getOption('timeout'), 20));
        $samplesPerShape = max(1, $this->intValue($input->getOption('samples-per-shape'), 2));
        $maxLinks = max(1, $this->intValue($input->getOption('max-links'), 2000));
        $pagesLimit = $this->intValue($input->getOption('pages-limit'), 0);
        $onlyWithArguments = $input->getOption('all-links') !== true;
        $requestOptions = $this->basicAuthResolver->buildRequestOptions(
            $this->stringValue($input->getOption('basic-auth')),
            $this->stringValue($input->getOption('basic-auth-env')),
        );

        $io->title(sprintf('Website Check — links: snapshot "%s" (%s)', $snapshot->label, $environment));

        $pages = array_keys($this->sitemapSnapshotLocator->findUrls($snapshot, $this->stringList($input->getOption('group'))));
        if ($pagesLimit > 0) {
            $pages = array_slice($pages, 0, $pagesLimit);
        }
        if ($pages === []) {
            $io->error(sprintf('The snapshot "%s" has no URLs in the selected groups.', $snapshot->label));
            return self::FAILURE;
        }

        $io->section(sprintf('Reading links from %d pages', count($pages)));
        $io->progressStart(count($pages));

        /** @var array<string, string> $linksToCheck link URL => page it was found on */
        $linksToCheck = [];
        /** @var array<string, int> $shapeCounts */
        $shapeCounts = [];
        $skippedByShape = 0;

        foreach ($pages as $pageUrl) {
            $page = $this->pageFetcher->fetch($pageUrl, $timeout, $requestOptions);
            $io->progressAdvance();
            if (!$page->isOk()) {
                continue;
            }

            foreach ($this->pageLinkCollector->collect($page->body, $pageUrl, $onlyWithArguments) as $link) {
                if (isset($linksToCheck[$link]) || count($linksToCheck) >= $maxLinks) {
                    continue;
                }
                $shape = $this->pageLinkCollector->shapeOf($link);
                $seen = $shapeCounts[$shape] ?? 0;
                if ($seen >= $samplesPerShape) {
                    $skippedByShape++;
                    continue;
                }
                $shapeCounts[$shape] = $seen + 1;
                $linksToCheck[$link] = $pageUrl;
            }
        }

        $io->progressFinish();

        if ($linksToCheck === []) {
            $io->success('No plugin links found on those pages — nothing to check.');
            return self::SUCCESS;
        }

        $io->writeln(sprintf(
            '%d links to check, covering %d distinct shapes (%d further links skipped as same-shape duplicates).',
            count($linksToCheck),
            count($shapeCounts),
            $skippedByShape,
        ));

        $io->section('Checking links');
        $io->progressStart(count($linksToCheck));

        /** @var array<string, string|null> $baselineCache URL without arguments => normalised body */
        $baselineCache = [];
        $notOk = 0;
        $withMarker = 0;
        $ignoredArguments = 0;
        $findings = [];

        foreach ($linksToCheck as $link => $foundOn) {
            $linkedPage = $this->pageFetcher->fetch($link, $timeout, $requestOptions);
            $httpStatus = $linkedPage->httpStatus;
            $errorMarker = $this->errorMarkerDetector->detectFor($linkedPage);

            if (!$linkedPage->isOk()) {
                $notOk++;
            } elseif ($errorMarker === '') {
                $baseUrl = $this->pageLinkCollector->withoutArguments($link);
                if ($baseUrl !== $link) {
                    if (!array_key_exists($baseUrl, $baselineCache)) {
                        $baselinePage = $this->pageFetcher->fetch($baseUrl, $timeout, $requestOptions);
                        $baselineCache[$baseUrl] = $baselinePage->isOk() ? $this->comparableBody($baselinePage->body) : null;
                    }
                    $baseline = $baselineCache[$baseUrl];
                    if ($baseline !== null && $baseline === $this->comparableBody($linkedPage->body)) {
                        $errorMarker = self::MARKER_ARGUMENTS_IGNORED;
                        $ignoredArguments++;
                    }
                }
            }

            if ($errorMarker !== '' && $errorMarker !== self::MARKER_ARGUMENTS_IGNORED) {
                $withMarker++;
            }
            if ($httpStatus !== 200 || $errorMarker !== '') {
                $findings[] = sprintf('%s  %s  (on %s)', $httpStatus === 200 ? $errorMarker : (string)$httpStatus, $link, $foundOn);
            }

            $this->checkResultRepository->storeResult(
                $link,
                $environment,
                $foundOn,
                $this->pageUidResolver->resolve($link),
                $httpStatus,
                $errorMarker,
                time(),
            );
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->writeln(sprintf(
            '%d links checked, %d without HTTP 200, %d with an error marker, %d whose arguments had no effect.',
            count($linksToCheck),
            $notOk,
            $withMarker,
            $ignoredArguments,
        ));

        if ($findings !== []) {
            $io->section('Findings');
            $io->listing(array_slice($findings, 0, 60));
            if (count($findings) > 60) {
                $io->writeln(sprintf('… and %d more, see the Website Check backend module.', count($findings) - 60));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Strips <head> before comparing. A canonical tag or hreflang set repeats the
     * current URL and would make every page differ from its own argument-less
     * version, hiding exactly what this looks for.
     */
    private function comparableBody(string $html): string
    {
        $withoutHead = preg_replace('#<head\b.*?</head>#is', '', $html);

        return $withoutHead ?? $html;
    }
}
