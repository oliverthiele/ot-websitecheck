<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\IdentityPatterns;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;

class IdentityExtractor
{
    public function extract(string $html, IdentityPatterns $patterns): PageIdentity
    {
        $pageUid = 0;
        if ($patterns->pageUid !== '' && preg_match($patterns->pageUid, $html, $matches) === 1) {
            $pageUid = (int)($matches[1] ?? 0);
        }

        $language = '';
        if ($patterns->language !== '' && preg_match($patterns->language, $html, $matches) === 1) {
            // Normalised, so "de-DE" on one system and "de-de" on the other
            // do not count as a language change.
            $language = strtolower($matches[1] ?? '');
        }

        $recordTable = '';
        $recordUid = 0;
        if ($patterns->record !== '' && preg_match($patterns->record, $html, $matches) === 1) {
            $recordTable = $matches[1] ?? '';
            $recordUid = (int)($matches[2] ?? 0);
        }

        return new PageIdentity($pageUid, $language, $recordTable, $recordUid);
    }
}
