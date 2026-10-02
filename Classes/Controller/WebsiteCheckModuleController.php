<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use OliverThiele\OtWebsitecheck\Service\FindingGuide;
use OliverThiele\OtWebsitecheck\Service\SnapshotOptionsProvider;
use OliverThiele\OtWebsitecheck\Utility\RowValue;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;
use Psr\Http\Message\ResponseInterface;
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

    public function __construct(
        private readonly CheckResultRepository $checkResultRepository,
        private readonly SnapshotOptionsProvider $snapshotOptionsProvider,
        private readonly FindingGuide $findingGuide,
    ) {
    }

    public function indexAction(string $environment = '', bool $onlyProblems = true, bool $onlyUnreviewed = false, string $marker = '', string $actor = '', int $page = 1): ResponseInterface
    {
        $environments = $this->checkResultRepository->findDistinctEnvironments();
        $markers = $this->checkResultRepository->findDistinctMarkers($environment);
        if (!in_array($marker, $markers, true)) {
            $marker = '';
        }
        $markerFilter = $marker === '' ? [] : [$marker];
        if (!in_array($actor, FindingGuide::ACTORS, true)) {
            $actor = '';
        }
        $actorFilter = $actor === '' ? null : [
            'markers' => $this->findingGuide->filterMarkers($markers, $actor),
            'plain' => match ($actor) {
                FindingGuide::ACTOR_EDITOR => CheckResultRepository::PLAIN_NOT_FOUND,
                FindingGuide::ACTOR_INTEGRATOR => CheckResultRepository::PLAIN_OTHER_ERROR,
                default => CheckResultRepository::PLAIN_OK,
            },
        ];
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
            'markerOptions' => ['' => $this->translate('statusResults.allMarkers')] + $this->buildMarkerOptions($markers),
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
        $markerKey = 'errorMarker.' . $marker;
        $markerHelp = $marker === '' ? '' : $this->translate($markerKey . '.help');

        return $result + [
            'suggestedUrl' => $suggestedUrl,
            'guide' => $this->findingGuide->forStatusResult($marker, RowValue::int($result, 'http_status')),
            // Exception class names are markers as well and share one explanation.
            'markerHelp' => $markerHelp !== $markerKey . '.help' ? $markerHelp : $this->translate('errorMarker.exception.help'),
        ];
    }

    /**
     * @param list<string> $markers
     * @return array<string, string>
     */
    private function buildMarkerOptions(array $markers): array
    {
        $options = [];
        foreach ($markers as $marker) {
            $key = 'errorMarker.' . $marker;
            $label = $this->translate($key);
            // Exception class names are markers as well and have no label.
            $options[$marker] = $label !== $key ? $label : $marker;
        }

        return $options;
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
