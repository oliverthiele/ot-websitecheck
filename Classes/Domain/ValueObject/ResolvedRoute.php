<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

use TYPO3\CMS\Core\Routing\PageArguments;

/**
 * What the local routing reads from a URL: the page with its arguments, and
 * the language whose base the path lies below.
 */
final readonly class ResolvedRoute
{
    public function __construct(
        public PageArguments $pageArguments,
        public int $languageId,
    ) {
    }
}
