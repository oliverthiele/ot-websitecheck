<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotImporter;
use OliverThiele\OtWebsitecheck\Service\SnapshotOverviewBuilder;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\AllowedMethodsTrait;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Lists the stored sitemap snapshots and offers the import form. Inside a
 * snapshot every language is one row with its URL count per sitemap group,
 * and its sitemap files below — readable for many languages and paginated
 * sitemaps alike.
 */
class SitemapSnapshotModuleController extends AbstractModuleController
{
    use AllowedMethodsTrait;

    private const int MAXIMUM_MATRIX_GROUPS = 5;

    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly SiteBaseProvider $siteBaseProvider,
        private readonly SnapshotOverviewBuilder $snapshotOverviewBuilder,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $documentsBySnapshot = $this->sitemapSnapshotRepository->findDocumentSummaries();

        $snapshots = [];
        $languageOptions = [];
        foreach ($this->sitemapSnapshotRepository->findAll() as $snapshot) {
            $overview = $this->snapshotOverviewBuilder->build($documentsBySnapshot[$snapshot->uid] ?? []);
            // Beyond this, one column per group gets too wide; the groups are listed as text instead.
            $matrix = count($overview['groupNames']) <= self::MAXIMUM_MATRIX_GROUPS;
            foreach ($overview['languages'] as $languageRow) {
                $languageOptions[$languageRow['language']] = true;
            }
            $snapshots[] = [
                'uid' => $snapshot->uid,
                'label' => $snapshot->label,
                'startUrl' => $snapshot->startUrl,
                'fetchedAt' => $snapshot->fetchedAt,
                'labelContainsFetchedAt' => str_contains($snapshot->label, date(SitemapSnapshotImporter::DEFAULT_LABEL_DATE_FORMAT, $snapshot->fetchedAt)),
                'note' => $snapshot->note,
                'locked' => $snapshot->locked,
                'complete' => $snapshot->isComplete(),
                'overview' => $overview,
                'matrix' => $matrix,
                // language, groups, URLs, sitemaps, index
                'columnCount' => 4 + ($matrix ? count($overview['groupNames']) : 1),
            ];
        }
        ksort($languageOptions, SORT_STRING);

        $moduleTemplate = $this->createModuleTemplate();
        $moduleTemplate->assignMultiple([
            'snapshots' => $snapshots,
            'bases' => $this->siteBaseProvider->getBases(),
            'languageOptions' => array_map('strval', array_keys($languageOptions)),
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
}
