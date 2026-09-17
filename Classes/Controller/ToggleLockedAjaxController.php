<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Security\BackendAccessGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX endpoint for the lock button of a sitemap snapshot. A locked
 * snapshot can be deleted neither in the module nor by the cleanup command.
 */
class ToggleLockedAjaxController
{
    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
        private readonly BackendAccessGuard $backendAccessGuard,
    ) {
    }

    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->backendAccessGuard->isAllowed()) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }
        $parsedBody = $request->getParsedBody();
        $uidValue = is_array($parsedBody) ? ($parsedBody['uid'] ?? null) : null;
        $uid = is_numeric($uidValue) ? (int)$uidValue : 0;
        if ($uid <= 0) {
            return new JsonResponse(['error' => 'Missing or invalid "uid".'], 400);
        }

        $locked = $this->sitemapSnapshotRepository->toggleLocked($uid);
        if ($locked === null) {
            return new JsonResponse(['error' => 'No snapshot found for this uid.'], 404);
        }

        return new JsonResponse(['locked' => $locked]);
    }
}
