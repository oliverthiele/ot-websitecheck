<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\ValueObject;

/**
 * Regular expressions that read a page's identity out of its HTML.
 *
 * TYPO3 renders no page uid into the frontend by default, so the checked site
 * has to provide the markers — see README, "Requirements on the checked site".
 */
final readonly class IdentityPatterns
{
    /**
     * Capture group 1: page uid. Matches <body id="page-123"> and <body id="p123">.
     */
    public const string DEFAULT_PAGE_UID = '/<body\b[^>]*\bid="p(?:age-)?(\d+)"/i';

    /**
     * Capture group 1: language, from <html lang="de-DE">.
     */
    public const string DEFAULT_LANGUAGE = '/<html\b[^>]*\blang="([^"]+)"/i';

    /**
     * Capture group 1: table, group 2: record uid.
     */
    public const string DEFAULT_RECORD = '/<meta\b[^>]*\bname="websitecheck:record"[^>]*\bcontent="([a-z0-9_]+):(\d+)"/i';

    public function __construct(
        public string $pageUid = self::DEFAULT_PAGE_UID,
        public string $language = self::DEFAULT_LANGUAGE,
        public string $record = self::DEFAULT_RECORD,
    ) {}

    /**
     * @return list<string> The patterns PCRE refuses to compile.
     */
    public function findInvalidPatterns(): array
    {
        $invalidPatterns = [];
        foreach ([$this->pageUid, $this->language, $this->record] as $pattern) {
            if ($pattern !== '' && @preg_match($pattern, '') === false) {
                $invalidPatterns[] = $pattern;
            }
        }

        return $invalidPatterns;
    }
}
