<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Lists the stored sitemap snapshots and offers the import form. Inside a
 * snapshot every language and sitemap group is one row, with its sitemap files
 * below — a list that stays readable for many languages and paginated sitemaps.
 */
class SitemapSnapshotModuleController extends AbstractModuleController
{
    use AllowedMethodsTrait;

    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly SiteBaseProvider $siteBaseProvider,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $documentsBySnapshot = $this->sitemapSnapshotRepository->findDocumentSummaries();

        $snapshots = [];
        $languageOptions = [];
        $groupOptions = [];
        foreach ($this->sitemapSnapshotRepository->findAll() as $snapshot) {
            $rows = $this->buildRows($documentsBySnapshot[$snapshot->uid] ?? []);
            $languages = [];
            foreach ($rows as $row) {
                $languages[$row['language']] = true;
                $languageOptions[$row['language']] = true;
                $groupOptions[$row['sitemapGroup']] = true;
            }
            $snapshots[] = [
                'uid' => $snapshot->uid,
                'label' => $snapshot->label,
                'startUrl' => $snapshot->startUrl,
                'fetchedAt' => $snapshot->fetchedAt,
                'note' => $snapshot->note,
                'locked' => $snapshot->locked,
                'complete' => $snapshot->isComplete(),
                'rows' => $rows,
                'languageCount' => count($languages),
                'fileCount' => array_sum(array_column($rows, 'fileCount')),
                'urlCount' => array_sum(array_column($rows, 'urlCount')),
                'failedCount' => array_sum(array_column($rows, 'failedCount')),
            ];
        }
        ksort($languageOptions);
        ksort($groupOptions);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'snapshots' => $snapshots,
            'bases' => $this->siteBaseProvider->getBases(),
            'languageOptions' => array_map('strval', array_keys($languageOptions)),
            'groupOptions' => array_map('strval', array_keys($groupOptions)),
        ]);

        return $moduleTemplate->renderResponse('SitemapSnapshotModule/Index');
    }

    public function initializeDeleteAction(): void
    {
        $this->assertAllowedHttpMethod($this->request, 'POST');
    }

    public function deleteAction(int $snapshot): ResponseInterface
    {
        if ($this->sitemapSnapshotRepository->findByUid($snapshot)?->locked === true) {
            $this->addFlashMessage($this->translate('flash.deleteSnapshot.locked'), '', ContextualFeedbackSeverity::WARNING);

            return $this->redirect('index');
        }

        $deleted = $this->sitemapSnapshotRepository->deleteSnapshot($snapshot);
        $this->addFlashMessage(
            $this->translate($deleted ? 'flash.deleteSnapshot.success' : 'flash.deleteSnapshot.notFound'),
            '',
            $deleted ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING,
        );

        return $this->redirect('index');
    }

    /**
     * One row per language and sitemap group, ordered by language, with the
     * sitemap index of a language as its own row without a group.
     *
     * @param list<array{uid: int, language: string, url: string, sitemapGroup: string, type: string, httpStatus: int, urlCount: int}> $documents
     * @return list<array{key: string, language: string, sitemapGroup: string, isIndex: bool, fileCount: int, urlCount: int, failedCount: int, files: list<array<string, mixed>>}>
     */
    private function buildRows(array $documents): array
    {
        $rows = [];
        foreach ($documents as $document) {
            $isIndex = $document['type'] === SitemapDocument::TYPE_INDEX;
            $key = $document['language'] . '|' . ($isIndex ? '' : $document['sitemapGroup']) . '|' . ($isIndex ? 'index' : 'group');
            $failed = !SitemapDocument::isUsableType($document['type']);

            $rows[$key] ??= [
                'key' => 'row' . md5($key),
                'language' => $document['language'],
                'sitemapGroup' => $isIndex ? '' : $document['sitemapGroup'],
                'isIndex' => $isIndex,
                'fileCount' => 0,
                'urlCount' => 0,
                'failedCount' => 0,
                'files' => [],
            ];
            $rows[$key]['fileCount']++;
            $rows[$key]['urlCount'] += $document['urlCount'];
            $rows[$key]['failedCount'] += $failed ? 1 : 0;
            $rows[$key]['files'][] = [
                'uid' => $document['uid'],
                'url' => $document['url'],
                'type' => $document['type'],
                'httpStatus' => $document['httpStatus'],
                'urlCount' => $document['urlCount'],
                'failed' => $failed,
            ];
        }

        uasort($rows, static fn(array $left, array $right): int => [$left['language'], !$left['isIndex'], $left['sitemapGroup']]
            <=> [$right['language'], !$right['isIndex'], $right['sitemapGroup']]);

        return array_values($rows);
    }
}
