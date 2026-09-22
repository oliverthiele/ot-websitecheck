<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use OliverThiele\OtWebsitecheck\Service\ArchiveDirectory;
use OliverThiele\OtWebsitecheck\Service\ArchiveFileService;
use OliverThiele\OtWebsitecheck\Service\MigrationAnalyzer;
use OliverThiele\OtWebsitecheck\Service\MigrationCheckSuggestion;
use OliverThiele\OtWebsitecheck\Service\SnapshotOptionsProvider;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Review the results of the `websitecheck:migrationcheck` command: one section
 * per sitemap group, inside it one block per page or record, inside that the
 * reference and target row of every language.
 */
class MigrationCheckModuleController extends AbstractModuleController
{
    use AllowedMethodsTrait;

    public function __construct(
        private readonly ObservationRepository $observationRepository,
        private readonly MigrationRunRepository $migrationRunRepository,
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly MigrationCheckSuggestion $migrationCheckSuggestion,
        private readonly SnapshotOptionsProvider $snapshotOptionsProvider,
        private readonly ArchiveDirectory $archiveDirectory,
        private readonly ArchiveFileService $archiveFileService,
    ) {
    }

    public function indexAction(
        string $run = '',
        string $group = '',
        string $language = '',
        string $verdict = '',
        bool $onlyProblems = true,
        bool $onlyUnreviewed = false,
        bool $showTargetSitemap = false,
    ): ResponseInterface {
        $runs = $this->observationRepository->findDistinctRuns();
        if ($run === '' || !in_array($run, $runs, true)) {
            $run = $runs[0] ?? '';
        }
        $observations = $run === '' ? [] : $this->observationRepository->findByRun($run);

        $referenceByPath = [];
        $targetByPath = [];
        $targetSitemapObservations = [];
        foreach ($observations as $observation) {
            match ($observation->role) {
                Observation::ROLE_REFERENCE => $referenceByPath[$observation->requestedPath] = $observation,
                Observation::ROLE_TARGET => $targetByPath[$observation->requestedPath] = $observation,
                Observation::ROLE_TARGET_SITEMAP => $targetSitemapObservations[] = $observation,
                default => null,
            };
        }

        $groupOptions = [];
        $languageOptions = [];
        $verdictCounts = [];
        /** @var array<string, array<string, list<Observation>>> $observationsBySection sitemap group => identity key => observations */
        $observationsBySection = [];
        foreach ($referenceByPath as $path => $reference) {
            $target = $targetByPath[$path] ?? null;
            $rowLanguage = $reference->identity->language !== '' ? $reference->identity->language : ($target->identity->language ?? '');

            $groupOptions[$reference->sitemapGroup] = $reference->sitemapGroup;
            if ($rowLanguage !== '') {
                $languageOptions[$rowLanguage] = $rowLanguage;
            }
            if ($target !== null) {
                $verdictKey = $this->verdictKey($target);
                $verdictCounts[$verdictKey] = ($verdictCounts[$verdictKey] ?? 0) + 1;
            }

            if (($group !== '' && $reference->sitemapGroup !== $group)
                || ($language !== '' && $rowLanguage !== $language)
                || ($verdict !== '' && ($target === null || $this->verdictKey($target) !== $verdict))
                // An explicitly chosen verdict is shown whether it counts as a problem or not.
                || ($onlyProblems && $verdict === '' && !$this->isProblem($reference, $target))
                || ($onlyUnreviewed && ($target === null || $target->reviewed))
            ) {
                continue;
            }

            $identityKey = $this->buildIdentityKey($reference);
            $observationsBySection[$reference->sitemapGroup][$identityKey][] = $reference;
            if ($target !== null) {
                $observationsBySection[$reference->sitemapGroup][$identityKey][] = $target;
            }
        }

        if ($showTargetSitemap) {
            foreach ($targetSitemapObservations as $observation) {
                $identityKey = $this->buildIdentityKey($observation);
                foreach (array_keys($observationsBySection) as $sectionName) {
                    if (isset($observationsBySection[$sectionName][$identityKey])) {
                        $observationsBySection[$sectionName][$identityKey][] = $observation;
                        break;
                    }
                }
            }
        }
        $sections = $this->buildSections($observationsBySection);

        ksort($groupOptions);
        ksort($languageOptions);
        ksort($verdictCounts);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'sections' => $sections,
            'runOptions' => array_combine($runs, $runs),
            'groupOptions' => ['' => $this->translate('filter.allGroups')] + $groupOptions,
            'languageOptions' => ['' => $this->translate('filter.allLanguages')] + $languageOptions,
            'verdictOptions' => ['' => $this->translate('filter.allVerdicts')] + $this->buildVerdictOptions(array_keys($verdictCounts)),
            'verdictCounts' => $verdictCounts,
            'currentRun' => $run,
            'runSnapshots' => $this->buildRunSnapshots($run),
            'currentGroup' => $group,
            'currentLanguage' => $language,
            'currentVerdict' => $verdict,
            'onlyProblems' => $onlyProblems,
            'onlyUnreviewed' => $onlyUnreviewed,
            'showTargetSitemap' => $showTargetSitemap,
            'moduleToken' => $this->moduleToken(),
            'commandBuilder' => $this->buildCommandBuilder(),
            'archiveEnabled' => $this->isArchiveEnabled(),
        ]);

        return $moduleTemplate->renderResponse('MigrationCheckModule/Index');
    }

    public function initializeSaveRunAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function saveRunAction(string $run): ResponseInterface
    {
        try {
            $file = $this->archiveFileService->saveRun($run);
            $this->addFlashMessage(sprintf($this->translate('flash.archive.runSaved'), $file->name), '', ContextualFeedbackSeverity::OK);
        } catch (SnapshotArchiveException $exception) {
            $this->addFlashMessage($exception->getMessage(), $this->translate('flash.archive.failed'), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('index', null, null, ['run' => $run]);
    }

    public function initializeDeleteRunAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteRunAction(string $run): ResponseInterface
    {
        $deletedCount = $this->observationRepository->deleteRun($run);
        $this->migrationRunRepository->deleteRun($run);
        $this->addFlashMessage(
            sprintf($this->translate('flash.deleteRun.success'), $deletedCount, $run),
            '',
            ContextualFeedbackSeverity::OK,
        );

        return $this->redirect('index');
    }

    /**
     * What the form for a new run offers: every complete snapshot, the earlier
     * runs whose reference rows can be reused, and the suggested pair.
     *
     * @return array{snapshots: list<array{uid: int, label: string, host: string, environment: string, locked: bool, fetchedAt: int}>, runs: list<array{label: string, referenceSnapshotUid: int, referenceEnvironments: string}>, referenceUid: int, targetUid: int, today: string}
     */
    private function buildCommandBuilder(): array
    {
        $suggestion = $this->migrationCheckSuggestion->suggest($this->sitemapSnapshotRepository->findAll());
        $snapshots = $this->snapshotOptionsProvider->getCompleteSnapshots();

        $referenceEnvironments = $this->observationRepository->findReferenceEnvironmentsByRun();
        $runs = [];
        foreach ($this->migrationRunRepository->findAll() as $run) {
            if (!isset($referenceEnvironments[$run['runLabel']])) {
                continue;
            }
            $runs[] = [
                'label' => $run['runLabel'],
                'referenceSnapshotUid' => $run['referenceSnapshotUid'],
                'referenceEnvironments' => implode(',', $referenceEnvironments[$run['runLabel']]),
            ];
        }

        return [
            'snapshots' => $snapshots,
            'runs' => $runs,
            'referenceUid' => $suggestion['reference']->uid ?? 0,
            'targetUid' => $suggestion['target']->uid ?? 0,
            'today' => date('Y-m-d'),
        ];
    }

    /**
     * The snapshots a run compared. Runs from before snapshots existed have none.
     *
     * @return array{reference: string, referenceEnvironment: string, target: string, targetEnvironment: string, targetHost: string, startedAt: int}|null
     */
    private function buildRunSnapshots(string $run): ?array
    {
        $migrationRun = $this->migrationRunRepository->findByLabel($run);
        if ($migrationRun === null) {
            return null;
        }
        $deletedLabel = $this->translate('run.snapshotDeleted');
        $reference = $this->sitemapSnapshotRepository->findByUid($migrationRun['referenceSnapshotUid']);
        $target = $this->sitemapSnapshotRepository->findByUid($migrationRun['targetSnapshotUid']);

        return [
            'reference' => $reference->label ?? $deletedLabel,
            'referenceEnvironment' => $reference->environment ?? '',
            'target' => $target->label ?? $deletedLabel,
            'targetEnvironment' => $target->environment ?? '',
            'targetHost' => $migrationRun['targetHost'],
            'startedAt' => $migrationRun['startedAt'],
        ];
    }

    private function isArchiveEnabled(): bool
    {
        try {
            return $this->archiveDirectory->getPath() !== '';
        } catch (SnapshotArchiveException) {
            return false;
        }
    }

    private function isProblem(Observation $reference, ?Observation $target): bool
    {
        if ($target === null) {
            return false;
        }

        // A reference URL that fails is no migration problem, but a broken URL
        // search engines know from the sitemap — worth seeing all the same.
        return in_array($target->verdict, MigrationAnalyzer::PROBLEM_VERDICTS, true)
            || $target->verdict === MigrationAnalyzer::VERDICT_REFERENCE_NOT_OK
            || $target->warnings !== []
            || $reference->warnings !== [];
    }

    private function buildIdentityKey(Observation $observation): string
    {
        $identity = $observation->identity;
        if ($identity->hasRecord()) {
            return 'record:' . $identity->recordTable . ':' . $identity->recordUid;
        }
        if ($identity->hasPage()) {
            return 'page:' . $identity->pageUid;
        }

        return 'path:' . $observation->requestedPath;
    }

    /**
     * Titles come from the local database, which may differ from the checked
     * environments — they are a reading aid, not part of the comparison.
     *
     * @return array<string, mixed>
     */
    private function buildIdentityHeader(Observation $observation): array
    {
        $identity = $observation->identity;
        $title = '';
        $tca = $GLOBALS['TCA'] ?? null;
        if ($identity->hasRecord() && is_array($tca) && is_array($tca[$identity->recordTable] ?? null)) {
            $record = BackendUtility::getRecord($identity->recordTable, $identity->recordUid);
            $title = is_array($record) ? BackendUtility::getRecordTitle($identity->recordTable, $record) : '';
        } elseif ($identity->hasPage()) {
            $page = BackendUtility::getRecord('pages', $identity->pageUid, 'title');
            $pageTitle = is_array($page) ? ($page['title'] ?? '') : '';
            $title = is_string($pageTitle) ? $pageTitle : '';
        }

        return [
            'pageUid' => $identity->pageUid,
            'recordTable' => $identity->recordTable,
            'recordUid' => $identity->recordUid,
            'title' => $title,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(Observation $observation): array
    {
        $host = parse_url($observation->requestedUrl, PHP_URL_HOST);
        $chain = [];
        $previousStatus = 0;
        foreach ($observation->redirectChain as $index => $step) {
            $chain[] = [
                'label' => $this->shortenUrl($step['url'], is_string($host) ? $host : ''),
                'url' => $step['url'],
                'status' => $index === 0 ? 0 : $previousStatus,
            ];
            $previousStatus = $step['status'];
        }

        return [
            'uid' => $observation->uid,
            'environment' => $observation->environment,
            'role' => $observation->role,
            'language' => $observation->identity->language,
            'pageUid' => $observation->identity->pageUid,
            'firstStatus' => $observation->firstStatus,
            'finalStatus' => $observation->finalStatus,
            'firstStatusSeverity' => match (true) {
                $observation->firstStatus === 200 => 'success',
                $observation->hopCount > 0 => 'info',
                default => 'danger',
            },
            'finalStatusSeverity' => $observation->finalStatus === 200 ? 'success' : 'danger',
            'finalStatusHelp' => $this->statusHelpKey($observation->finalStatus),
            'requestedUrl' => $observation->requestedUrl,
            'finalUrl' => $observation->finalUrl,
            'finalPath' => $observation->finalPath,
            'hopCount' => $observation->hopCount,
            'chain' => $observation->hopCount > 0 ? $chain : [],
            'abortReason' => $observation->abortReason,
            'verdict' => $this->verdictKey($observation),
            'verdictSeverity' => $this->verdictSeverity($observation->verdict),
            'warnings' => $observation->warnings,
            'suggestedTarget' => $observation->suggestedTarget,
            'reviewed' => $observation->reviewed,
            'note' => $observation->note,
        ];
    }

    /**
     * Rows inside an identity are ordered by language and requested path, so the
     * reference and target row of the same URL stand next to each other.
     *
     * @param array<string, array<string, list<Observation>>> $observationsBySection
     * @return list<array{name: string, identities: list<array{header: array<string, mixed>, rows: list<array<string, mixed>>}>}>
     */
    private function buildSections(array $observationsBySection): array
    {
        ksort($observationsBySection);
        $roleOrder = [Observation::ROLE_REFERENCE => 0, Observation::ROLE_TARGET => 1, Observation::ROLE_TARGET_SITEMAP => 2];

        $sections = [];
        foreach ($observationsBySection as $sectionName => $observationsByIdentity) {
            $identities = [];
            foreach ($observationsByIdentity as $observations) {
                usort($observations, static fn(Observation $left, Observation $right): int => [$left->identity->language, $left->requestedPath, $roleOrder[$left->role] ?? 9]
                    <=> [$right->identity->language, $right->requestedPath, $roleOrder[$right->role] ?? 9]);
                $identities[] = [
                    'header' => $this->buildIdentityHeader($observations[0]),
                    'rows' => array_map($this->buildRow(...), $observations),
                ];
            }
            $sections[] = ['name' => (string)$sectionName, 'identities' => $identities];
        }

        return $sections;
    }

    /**
     * @param list<string> $verdicts
     * @return array<string, string>
     */
    private function buildVerdictOptions(array $verdicts): array
    {
        $options = [];
        foreach ($verdicts as $verdict) {
            if ($verdict !== '') {
                $options[$verdict] = $this->translate('verdict.' . $verdict);
            }
        }

        return $options;
    }

    /**
     * Rows are stored before the run is analysed; an empty verdict means the
     * run is still in progress or was aborted before its analysis.
     */
    private function verdictKey(Observation $observation): string
    {
        return $observation->verdict !== '' ? $observation->verdict : 'notAnalyzed';
    }

    /**
     * The group of explanations for a final status that is not a success.
     */
    private function statusHelpKey(int $status): string
    {
        return match (true) {
            $status === 0 => 'noAnswer',
            $status === 401, $status === 403 => 'accessDenied',
            $status === 404, $status === 410 => 'notFound',
            $status >= 500 => 'serverError',
            $status >= 400 => 'clientError',
            default => '',
        };
    }

    private function verdictSeverity(string $verdict): string
    {
        return match ($verdict) {
            MigrationAnalyzer::VERDICT_OK, MigrationAnalyzer::VERDICT_MOVED_WITH_REDIRECT => 'success',
            MigrationAnalyzer::VERDICT_MISSING, MigrationAnalyzer::VERDICT_REDIRECT_BROKEN, MigrationAnalyzer::VERDICT_OTHER_CONTENT => 'danger',
            MigrationAnalyzer::VERDICT_IDENTITY_UNKNOWN, MigrationAnalyzer::VERDICT_REFERENCE_NOT_OK, MigrationAnalyzer::VERDICT_TIMEOUT => 'warning',
            default => 'default',
        };
    }

    private function shortenUrl(string $url, string $host): string
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        if ($host === '' || $urlHost !== $host) {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) ? $path : '/') . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}
