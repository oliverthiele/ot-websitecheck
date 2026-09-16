<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Security;

use TYPO3\CMS\Core\Context\Context;

/**
 * The one place that decides who may use the tools of this extension.
 *
 * The backend modules are admin-only, but backend AJAX routes are reachable for
 * every logged-in backend user — each AJAX endpoint therefore has to ask here
 * itself.
 */
class BackendAccessGuard
{
    public function __construct(
        private readonly Context $context,
    ) {}

    public function isAllowed(): bool
    {
        return $this->context->getPropertyFromAspect('backend.user', 'isAdmin', false) === true;
    }
}
