<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use OliverThiele\OtWebsitecheck\Service\SnapshotOptionsProvider;
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
    ) {
    }

    public function indexAction(string $environment = '', bool $onlyProblems = true, bool $onlyUnreviewed = false, int $page = 1): ResponseInterface
    {
        $environments = $this->checkResultRepository->findDistinctEnvironments();
        // A crawl of a large site stores tens of thousands of rows; the table
        // shows one page of them.
        $total = $this->checkResultRepository->countAll($environment, $onlyProblems, $onlyUnreviewed);
        $pageCount = max(1, (int)ceil($total / self::RESULTS_PER_PAGE));
        $page = min(max(1, $page), $pageCount);
        $offset = ($page - 1) * self::RESULTS_PER_PAGE;
        $environmentOptions = ['' => $this->translate('statusResults.allEnvironments')] + array_combine($environments, $environments);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'results' => $this->checkResultRepository->findAll($environment, $onlyProblems, $onlyUnreviewed, self::RESULTS_PER_PAGE, $offset),
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
