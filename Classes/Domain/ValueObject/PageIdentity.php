<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * What a rendered page is, independent of the URL it was reached under: the
 * TYPO3 page uid, the language, and — on detail pages — the record shown.
 */
final readonly class PageIdentity
{
    public function __construct(
        public int $pageUid = 0,
        public string $language = '',
        public string $recordTable = '',
        public int $recordUid = 0,
    ) {
    }

    public function hasPage(): bool
    {
        return $this->pageUid > 0;
    }

    public function hasRecord(): bool
    {
        return $this->recordTable !== '' && $this->recordUid > 0;
    }
}
