<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use OliverThiele\OtWebsitecheck\Service\BackendPageLinks;
use OliverThiele\OtWebsitecheck\Service\FindingGuide;
use OliverThiele\OtWebsitecheck\Service\SnapshotOptionsProvider;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Review the results of the `websitecheck:checksitemap` and
 * `websitecheck:crawllinks` commands, and compose their calls. The data has no
 * page context (stored at pid 0), so the module is not bound to the page tree.
 */
class WebsiteCheckModuleController extends AbstractModuleController
{
    use AllowedMethodsTrait;

    private const int RESULTS_PER_PAGE = 500;

    /**
     * Filter value for every exception class at once: one finding for an
     * editor, however many classes there are.
     */
    private const string RENDERING_ERROR = 'renderingError';

    public function __construct(
        private readonly CheckResultRepository $checkResultRepository,
        private readonly SnapshotOptionsProvider $snapshotOptionsProvider,
        private readonly FindingGuide $findingGuide,
        private readonly BackendPageLinks $backendPageLinks,
    ) {
    }

    public function indexAction(string $environment = '', bool $onlyProblems = true, bool $onlyUnreviewed = false, string $marker = '', string $actor = '', int $page = 1): ResponseInterface
    {
        $environments = $this->checkResultRepository->findDistinctEnvironments();
        $markers = $this->checkResultRepository->findDistinctMarkers($environment);
        $exceptionMarkers = array_values(array_filter($markers, $this->findingGuide->isExceptionMarker(...)));
        $markerFilter = match (true) {
            $marker === self::RENDERING_ERROR && $exceptionMarkers !== [] => $exceptionMarkers,
            in_array($marker, $markers, true) && !$this->findingGuide->isExceptionMarker($marker) => [$marker],
            default => [],
        };
        if ($markerFilter === []) {
            $marker = '';
        }
        if (!in_array($actor, FindingGuide::ACTORS, true)) {
            $actor = '';
        }
        $actorFilter = $actor === '' ? null : $this->buildActorFilter($actor, $markers);
        // "Nothing to do" lists what "only problems" hides; a chosen actor wins.
        $onlyProblems = $onlyProblems && $actor === '';
        // A crawl of a large site stores tens of thousands of rows; the table
        // shows one page of them.
        $total = $this->checkResultRepository->countAll($environment, $onlyProblems, $onlyUnreviewed, $markerFilter, $actorFilter);
        $pageCount = max(1, (int)ceil($total / self::RESULTS_PER_PAGE));
        $page = min(max(1, $page), $pageCount);
        $offset = ($page - 1) * self::RESULTS_PER_PAGE;
        $environmentOptions = ['' => $this->translate('statusResults.allEnvironments')] + array_combine($environments, $environments);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'results' => array_map(
                $this->addGuidance(...),
                $this->checkResultRepository->findAll($environment, $onlyProblems, $onlyUnreviewed, self::RESULTS_PER_PAGE, $offset, $markerFilter, $actorFilter),
            ),
            'pagination' => [
                'page' => $page,
                'pageCount' => $pageCount,
                'total' => $total,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + self::RESULTS_PER_PAGE, $total),
                'previousPage' => $page > 1 ? $page - 1 : 0,
                'nextPage' => $page < $pageCount ? $page + 1 : 0,
            ],
            'environmentOptions' => $environmentOptions,
            'markerOptions' => ['' => $this->translate('statusResults.allMarkers')] + $this->buildMarkerOptions($markers, $exceptionMarkers !== []),
            'actorCounts' => $this->countByActor($environment, $markers),
            'currentMarker' => $marker,
            'actorOptions' => $this->buildActorOptions(),
            'currentActor' => $actor,
            // Results of any environment — the filter must stay even when the chosen one has none.
            'hasResults' => $environments !== [],
            'sources' => $this->checkResultRepository->findDistinctSources($environment),
            'summary' => $this->checkResultRepository->summarize($environment),
            'currentEnvironment' => $environment,
            'onlyProblems' => $onlyProblems,
            'onlyUnreviewed' => $onlyUnreviewed,
            'moduleToken' => $this->moduleToken(),
            // Newest first, so the first one is the suggestion.
            'commandSnapshots' => $this->snapshotOptionsProvider->getCompleteSnapshots(),
        ]);

        return $moduleTemplate->renderResponse('WebsiteCheckModule/Index');
    }

    /**
     * Who acts on a result, the help entry that explains it, and where the URL
     * should lead in the end: the page its canonical names, or else the URL
     * its redirects ended on — empty when that is the URL itself.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function addGuidance(array $result): array
    {
        $url = RowValue::string($result, 'url');
        $finalUrl = RowValue::string($result, 'final_url');
        $canonicalUrl = RowValue::string($result, 'canonical_url');
        $answeredUrl = $finalUrl !== '' ? $finalUrl : $url;
        $suggestedUrl = match (true) {
            $canonicalUrl !== '' && UrlUtility::comparablePath($canonicalUrl) !== UrlUtility::comparablePath($answeredUrl) => $canonicalUrl,
            $finalUrl !== '' && $finalUrl !== $url => $finalUrl,
            default => '',
        };

        $marker = RowValue::string($result, 'error_marker');
        $isException = $this->findingGuide->isExceptionMarker($marker);
        $pageUid = RowValue::int($result, 'page_uid');
        $page = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid, 'title') : null;
        $pageTitle = $page['title'] ?? '';

        return $result + [
            'pageTitle' => is_string($pageTitle) ? $pageTitle : '',
            // An exception class is shown by its short name; the popover names it in full.
            'markerLabel' => match (true) {
                $marker === '' => '',
                $isException => substr((string)strrchr('\\' . $marker, '\\'), 1),
                default => $this->translate('errorMarker.' . $marker),
            },
            'markerHelp' => $this->translate($isException ? 'errorMarker.exception.help' : 'errorMarker.' . $marker . '.help'),
            'suggestedUrl' => $suggestedUrl,
            'backendLink' => $this->backendPageLinks->forPageOfUrl($url, RowValue::int($result, 'page_uid'), RowValue::int($result, 'language_uid'), $this->request),
            'languageTitle' => $this->backendPageLinks->findLanguageTitle(RowValue::int($result, 'page_uid'), RowValue::int($result, 'language_uid')),
            'guide' => $this->findingGuide->forStatusResult($marker, RowValue::int($result, 'http_status')),
        ];
    }

    /**
     * @param list<string> $markers
     * @return array<string, string>
     */
    private function buildMarkerOptions(array $markers, bool $hasExceptions): array
    {
        $options = [];
        foreach ($markers as $marker) {
            if (!$this->findingGuide->isExceptionMarker($marker)) {
                $options[$marker] = $this->translate('errorMarker.' . $marker);
            }
        }
        if ($hasExceptions) {
            $options[self::RENDERING_ERROR] = $this->translate('statusResults.renderingError');
        }

        return $options;
    }

    /**
     * @param list<string> $markers the markers stored for the environment
     * @return array{markers: list<string>, plain: string}
     */
    private function buildActorFilter(string $actor, array $markers): array
    {
        return [
            'markers' => $this->findingGuide->filterMarkers($markers, $actor),
            'plain' => match ($actor) {
                FindingGuide::ACTOR_EDITOR => CheckResultRepository::PLAIN_NOT_FOUND,
                FindingGuide::ACTOR_INTEGRATOR => CheckResultRepository::PLAIN_OTHER_ERROR,
                default => CheckResultRepository::PLAIN_OK,
            },
        ];
    }

    /**
     * How many results each actor has to look at; nothing to do is not counted.
     *
     * @param list<string> $markers
     * @return array<string, int>
     */
    private function countByActor(string $environment, array $markers): array
    {
        $counts = [];
        foreach ([FindingGuide::ACTOR_EDITOR, FindingGuide::ACTOR_INTEGRATOR] as $actor) {
            $counts[$actor] = $this->checkResultRepository->countAll($environment, false, false, [], $this->buildActorFilter($actor, $markers));
        }

        return $counts;
    }

    public function initializeDeleteAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteAction(int $uid): ResponseInterface
    {
        $this->checkResultRepository->deleteByUid($uid);
        $this->addFlashMessage($this->translate('flash.deleteResult.success'), '', ContextualFeedbackSeverity::OK);

        return $this->redirect('index');
    }

    public function initializeDeleteAllAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteAllAction(string $environment = ''): ResponseInterface
    {
        $deletedCount = $this->checkResultRepository->deleteAll($environment);
        $this->addFlashMessage(sprintf($this->translate('flash.deleteResults.success'), $deletedCount), '', ContextualFeedbackSeverity::OK);

        return $this->redirect('index');
    }
}
