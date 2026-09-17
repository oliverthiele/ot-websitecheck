<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Exception\SitemapImportException;
use OliverThiele\OtWebsitecheck\Security\BackendAccessGuard;
use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use OliverThiele\OtWebsitecheck\Service\SitemapSnapshotImporter;
use OliverThiele\OtWebsitecheck\Utility\LabelUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX endpoints of the sitemap import form. The import runs as a
 * sequence of short requests — discover, start, one per language, finish — so
 * a site with many languages does not hit a request timeout.
 *
 * The server only ever fetches URLs on hosts of the configured sites: the
 * start URL has to be one of their bases, and every sitemap URL has to be on
 * one of their hosts. Basic Auth credentials are used for the request they
 * arrive with and are never stored.
 */
class SitemapImportAjaxController
{
    private const int TIMEOUT = 20;

    public function __construct(
        private readonly SitemapSnapshotImporter $sitemapSnapshotImporter,
        private readonly SiteBaseProvider $siteBaseProvider,
        private readonly BackendAccessGuard $backendAccessGuard,
    ) {
    }

    public function discoverAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->backendAccessGuard->isAllowed()) {
            return $this->errorResponse('error.import.accessDenied', 403);
        }
        $base = $this->siteBaseProvider->findByUrl($this->bodyString($request, 'base'));
        if ($base === null) {
            return $this->errorResponse('error.import.unknownBase', 400);
        }

        $sitemaps = [];
        $sitemapUrls = $this->sitemapSnapshotImporter->discoverSitemaps($base->url, $base->sitemapPath, self::TIMEOUT, $this->requestOptions($request));
        foreach ($sitemapUrls as $language => $sitemapUrl) {
            $sitemaps[] = [
                'language' => (string)$language,
                'sitemapUrl' => $sitemapUrl,
                'allowed' => $this->siteBaseProvider->isConfiguredHost($sitemapUrl),
            ];
        }

        return new JsonResponse([
            'sitemaps' => $sitemaps,
            'defaultLabel' => $this->sitemapSnapshotImporter->buildDefaultLabel($base->url, time()),
        ]);
    }

    public function startAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->backendAccessGuard->isAllowed()) {
            return $this->errorResponse('error.import.accessDenied', 403);
        }
        $base = $this->siteBaseProvider->findByUrl($this->bodyString($request, 'base'));
        if ($base === null) {
            return $this->errorResponse('error.import.unknownBase', 400);
        }

        try {
            $snapshotUid = $this->sitemapSnapshotImporter->startSnapshot(
                $this->bodyString($request, 'label'),
                $base->url,
                $this->bodyString($request, 'note'),
                time(),
            );
        } catch (SitemapImportException $exception) {
            return $this->importErrorResponse($exception);
        }

        return new JsonResponse(['snapshotUid' => $snapshotUid]);
    }

    public function importLanguageAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->backendAccessGuard->isAllowed()) {
            return $this->errorResponse('error.import.accessDenied', 403);
        }
        $sitemapUrl = $this->bodyString($request, 'sitemapUrl');
        if (!$this->siteBaseProvider->isConfiguredHost($sitemapUrl)) {
            return $this->errorResponse('error.import.unknownHost', 400);
        }

        try {
            $result = $this->sitemapSnapshotImporter->importLanguage(
                $this->bodyInt($request, 'snapshotUid'),
                $this->bodyString($request, 'language'),
                $sitemapUrl,
                self::TIMEOUT,
                $this->requestOptions($request),
            );
        } catch (SitemapImportException $exception) {
            return $this->importErrorResponse($exception);
        }

        return new JsonResponse([
            'urlCount' => $result->getUrlCount(),
            'fileCount' => $result->fileCount,
            'failedCount' => count($result->failedDocuments),
        ]);
    }

    public function finishAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->backendAccessGuard->isAllowed()) {
            return $this->errorResponse('error.import.accessDenied', 403);
        }

        try {
            $urlCount = $this->sitemapSnapshotImporter->finishSnapshot($this->bodyInt($request, 'snapshotUid'));
        } catch (SitemapImportException $exception) {
            return $this->importErrorResponse($exception);
        }

        return new JsonResponse(['urlCount' => $urlCount]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(ServerRequestInterface $request): array
    {
        $user = $this->bodyString($request, 'basicAuthUser');
        $password = $this->bodyString($request, 'basicAuthPassword');

        return $user !== '' && $password !== '' ? ['auth' => [$user, $password]] : [];
    }

    private function importErrorResponse(SitemapImportException $exception): ResponseInterface
    {
        return new JsonResponse(['error' => LabelUtility::translate('error.import.' . $exception->reason)], 400);
    }

    private function errorResponse(string $key, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => LabelUtility::translate($key)], $status);
    }

    private function bodyString(ServerRequestInterface $request, string $field): string
    {
        $parsedBody = $request->getParsedBody();
        $value = is_array($parsedBody) ? ($parsedBody[$field] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    private function bodyInt(ServerRequestInterface $request, string $field): int
    {
        $value = $this->bodyString($request, $field);

        return is_numeric($value) ? (int)$value : 0;
    }
}
