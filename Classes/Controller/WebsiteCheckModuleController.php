<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Review results written by the `websitecheck:checksitemap` CLI command.
 * Data has no page context (stored at pid 0), so this is a "system" module
 * rather than a page-tree-bound "web" module.
 */
class WebsiteCheckModuleController extends AbstractModuleController
{
    use AllowedMethodsTrait;

    public function __construct(
        private readonly CheckResultRepository $checkResultRepository,
    ) {
    }

    public function indexAction(string $environment = '', bool $onlyProblems = true, bool $onlyUnreviewed = false): ResponseInterface
    {
        $environments = $this->checkResultRepository->findDistinctEnvironments();
        $environmentOptions = ['' => '— all environments —'] + array_combine($environments, $environments);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'results' => $this->checkResultRepository->findAll($environment, $onlyProblems, $onlyUnreviewed),
            'environmentOptions' => $environmentOptions,
            'sources' => $this->checkResultRepository->findDistinctSources($environment),
            'summary' => $this->checkResultRepository->summarize($environment),
            'currentEnvironment' => $environment,
            'onlyProblems' => $onlyProblems,
            'onlyUnreviewed' => $onlyUnreviewed,
            'moduleToken' => $this->moduleToken(),
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
        $this->addFlashMessage('The result was deleted.', '', ContextualFeedbackSeverity::OK);

        return $this->redirect('index');
    }

    public function initializeDeleteAllAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteAllAction(string $environment = ''): ResponseInterface
    {
        $deletedCount = $this->checkResultRepository->deleteAll($environment);
        $this->addFlashMessage(sprintf('%d result(s) deleted.', $deletedCount), '', ContextualFeedbackSeverity::OK);

        return $this->redirect('index');
    }
}
