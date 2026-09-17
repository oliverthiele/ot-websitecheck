<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Controller;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Security\BackendAccessGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Backend AJAX endpoint for the "reviewed" toggle button, so it can be
 * flipped in place without a full module reload.
 */
class ToggleReviewedAjaxController
{
    public function __construct(
        private readonly CheckResultRepository $checkResultRepository,
        private readonly ObservationRepository $observationRepository,
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

        // Without "table" the request comes from the status module, which
        // predates the parameter. Anything else must be on the allowlist.
        $tableValue = $parsedBody['table'] ?? '';
        if ($tableValue === ObservationRepository::TABLE) {
            $reviewed = $this->observationRepository->toggleReviewed($uid);
        } elseif ($tableValue === '' || $tableValue === CheckResultRepository::TABLE) {
            $reviewed = $this->checkResultRepository->toggleReviewed($uid);
        } else {
            return new JsonResponse(['error' => 'Unknown table.'], 400);
        }
        if ($reviewed === null) {
            return new JsonResponse(['error' => 'No result found for this uid.'], 404);
        }

        return new JsonResponse(['reviewed' => $reviewed]);
    }
}
