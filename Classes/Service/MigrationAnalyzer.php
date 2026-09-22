<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;

/**
 * Compares the observations of a migration check run: for every path that
 * works on the reference environment, does the target environment still lead
 * to the same content?
 *
 * Everything is derived from the stored rows, so a verdict can be recomputed
 * at any time — after a partial run, or after another environment was added.
 */
class MigrationAnalyzer
{
    public const string VERDICT_REFERENCE = 'reference';
    public const string VERDICT_REFERENCE_NOT_OK = 'referenceNotOk';
    public const string VERDICT_OK = 'ok';
    public const string VERDICT_MOVED_WITH_REDIRECT = 'movedWithRedirect';
    public const string VERDICT_MISSING = 'missing';
    public const string VERDICT_REDIRECT_BROKEN = 'redirectBroken';
    public const string VERDICT_OTHER_CONTENT = 'otherContent';
    public const string VERDICT_IDENTITY_UNKNOWN = 'identityUnknown';
    public const string VERDICT_TIMEOUT = 'timeout';
    public const string VERDICT_LISTED = 'listed';

    /**
     * Verdicts on a target row that need attention.
     */
    public const array PROBLEM_VERDICTS = [
        self::VERDICT_MISSING,
        self::VERDICT_REDIRECT_BROKEN,
        self::VERDICT_OTHER_CONTENT,
        self::VERDICT_IDENTITY_UNKNOWN,
        self::VERDICT_TIMEOUT,
    ];

    public const string WARNING_REDIRECT_CHAIN = 'redirectChain';
    public const string WARNING_TEMPORARY_REDIRECT = 'temporaryRedirect';
    public const string WARNING_REDIRECT_TO_ROOT_PAGE = 'redirectToRootPage';
    public const string WARNING_LISTED_URL_REDIRECTS = 'listedUrlRedirects';
    public const string WARNING_LANGUAGE_CHANGED = 'languageChanged';
    public const string WARNING_RECORD_IDENTITY_UNKNOWN = 'recordIdentityUnknown';
    public const string WARNING_DUPLICATE_DETAIL_PAGE = 'duplicateDetailPage';

    private const array TEMPORARY_REDIRECT_STATUS_CODES = [302, 303, 307];

    /**
     * A path that consists of nothing but an optional language segment, e.g.
     * "/", "/de/" or "/en-us".
     */
    private const string ROOT_PATH_PATTERN = '#^/(?:[a-z]{2}(?:[-_][a-z]{2})?/?)?$#i';

    /**
     * Reference and target rows are paired by their requested path. When a run
     * holds several reference or target environments, each target row is
     * compared with the reference row of the same path that was stored last.
     *
     * @param list<Observation> $observations All rows of one run.
     * @return array<int, array{verdict: string, warnings: list<string>, suggestedTarget: string}> Keyed by observation uid.
     */
    public function analyze(array $observations): array
    {
        $referenceByPath = [];
        $candidatesOnTarget = [];
        foreach ($observations as $observation) {
            if ($observation->role === Observation::ROLE_REFERENCE) {
                $referenceByPath[$observation->requestedPath] = $observation;
            }
            if (in_array($observation->role, [Observation::ROLE_TARGET, Observation::ROLE_TARGET_SITEMAP], true)
                && $observation->finalStatus === 200
            ) {
                $candidatesOnTarget[] = $observation;
            }
        }

        $crossRowWarnings = $this->collectCrossRowWarnings($observations);

        $results = [];
        foreach ($observations as $observation) {
            $warnings = $this->collectChainWarnings($observation);
            $suggestedTarget = '';

            if ($observation->role === Observation::ROLE_REFERENCE) {
                $verdict = $observation->finalStatus === 200 ? self::VERDICT_REFERENCE : self::VERDICT_REFERENCE_NOT_OK;
                if ($observation->hopCount > 0) {
                    $warnings[] = self::WARNING_LISTED_URL_REDIRECTS;
                }
            } elseif ($observation->role === Observation::ROLE_TARGET) {
                $reference = $referenceByPath[$observation->requestedPath] ?? null;
                $verdict = $reference === null ? '' : $this->resolveTargetVerdict($reference, $observation);
                if ($reference !== null && $this->isLanguageChanged($reference->identity, $observation->identity)) {
                    $warnings[] = self::WARNING_LANGUAGE_CHANGED;
                }
                if ($reference !== null && in_array($verdict, [self::VERDICT_MISSING, self::VERDICT_REDIRECT_BROKEN, self::VERDICT_OTHER_CONTENT], true)) {
                    $suggestedTarget = $this->findSuggestedTarget($reference, $observation, $candidatesOnTarget);
                }
            } elseif ($observation->role === Observation::ROLE_TARGET_SITEMAP) {
                $verdict = self::VERDICT_LISTED;
                if ($observation->hopCount > 0) {
                    $warnings[] = self::WARNING_LISTED_URL_REDIRECTS;
                }
            } else {
                $verdict = '';
            }

            $warnings = array_values(array_unique([...$warnings, ...($crossRowWarnings[$observation->uid] ?? [])]));
            $results[$observation->uid] = [
                'verdict' => $verdict,
                'warnings' => $warnings,
                'suggestedTarget' => $suggestedTarget,
            ];
        }

        return $results;
    }

    private function resolveTargetVerdict(Observation $reference, Observation $target): string
    {
        if ($reference->finalStatus !== 200) {
            return self::VERDICT_REFERENCE_NOT_OK;
        }
        // Too slow is not missing: the target may well have the page.
        if ($target->abortReason === RedirectChain::ABORT_TIMEOUT) {
            return self::VERDICT_TIMEOUT;
        }
        if (in_array($target->abortReason, [RedirectChain::ABORT_LOOP, RedirectChain::ABORT_HOP_LIMIT, RedirectChain::ABORT_MISSING_LOCATION], true)) {
            return self::VERDICT_REDIRECT_BROKEN;
        }
        if ($target->finalStatus !== 200) {
            return $target->hopCount > 0 ? self::VERDICT_REDIRECT_BROKEN : self::VERDICT_MISSING;
        }

        $sameContent = $this->isSameContent($reference->identity, $target->identity);
        if ($sameContent === null) {
            return self::VERDICT_IDENTITY_UNKNOWN;
        }
        if ($sameContent === false) {
            return self::VERDICT_OTHER_CONTENT;
        }

        return $target->hopCount > 0 ? self::VERDICT_MOVED_WITH_REDIRECT : self::VERDICT_OK;
    }

    /**
     * @return bool|null null when the markers needed for the comparison are missing on either side.
     */
    public function isSameContent(PageIdentity $reference, PageIdentity $candidate): ?bool
    {
        if ($this->isLanguageChanged($reference, $candidate)) {
            return false;
        }
        if ($reference->hasRecord()) {
            if (!$candidate->hasRecord()) {
                // The detail page may render the record, only without the
                // marker — or a different page. Not decidable either way.
                return $candidate->hasPage() && $candidate->pageUid !== $reference->pageUid ? false : null;
            }
            return $reference->recordTable === $candidate->recordTable && $reference->recordUid === $candidate->recordUid;
        }
        if (!$reference->hasPage() || !$candidate->hasPage()) {
            return null;
        }

        if ($reference->pageUid !== $candidate->pageUid) {
            return false;
        }

        // Same page, but only the candidate names a record: the reference
        // side has no record marker, so which record it showed is unknown.
        return $candidate->hasRecord() ? null : true;
    }

    private function isLanguageChanged(PageIdentity $reference, PageIdentity $candidate): bool
    {
        return $reference->language !== '' && $candidate->language !== '' && $reference->language !== $candidate->language;
    }

    /**
     * Only an unambiguous match is a suggestion. Without a record marker, every
     * record of a detail page shares its page uid, so several different paths
     * match — and any one of them would be a wrong redirect target.
     *
     * @param list<Observation> $candidates
     */
    private function findSuggestedTarget(Observation $reference, Observation $target, array $candidates): string
    {
        if (!$reference->identity->hasPage() && !$reference->identity->hasRecord()) {
            return '';
        }
        $matchingPaths = [];
        foreach ($candidates as $candidate) {
            if ($candidate->uid !== $target->uid && $this->isSameContent($reference->identity, $candidate->identity) === true) {
                $matchingPaths[$candidate->finalPath] = true;
            }
        }

        return count($matchingPaths) === 1 ? (string)array_key_first($matchingPaths) : '';
    }

    /**
     * @return list<string>
     */
    private function collectChainWarnings(Observation $observation): array
    {
        $warnings = [];
        if ($observation->hopCount > 1) {
            $warnings[] = self::WARNING_REDIRECT_CHAIN;
        }

        $redirectSteps = array_slice($observation->redirectChain, 0, $observation->hopCount);
        foreach ($redirectSteps as $step) {
            if (in_array($step['status'], self::TEMPORARY_REDIRECT_STATUS_CODES, true)) {
                $warnings[] = self::WARNING_TEMPORARY_REDIRECT;
                break;
            }
        }

        if ($observation->hopCount > 0
            && $observation->finalStatus === 200
            && preg_match(self::ROOT_PATH_PATTERN, $observation->finalPath) === 1
            && preg_match(self::ROOT_PATH_PATTERN, $observation->requestedPath) !== 1
        ) {
            $warnings[] = self::WARNING_REDIRECT_TO_ROOT_PAGE;
        }

        return $warnings;
    }

    /**
     * Warnings that only show when rows of the same environment are looked at
     * together.
     *
     * @param list<Observation> $observations
     * @return array<int, list<string>> Keyed by observation uid.
     */
    private function collectCrossRowWarnings(array $observations): array
    {
        $finalPathsByPage = [];
        $pageUidsByRecord = [];
        foreach ($observations as $observation) {
            if ($observation->finalStatus !== 200) {
                continue;
            }
            $identity = $observation->identity;
            if ($identity->hasRecord()) {
                $recordKey = implode('|', [$observation->environment, $identity->recordTable, $identity->recordUid, $identity->language]);
                $pageUidsByRecord[$recordKey][$identity->pageUid] = true;
            } elseif ($identity->hasPage()) {
                $pageKey = implode('|', [$observation->environment, $identity->pageUid, $identity->language]);
                $finalPathsByPage[$pageKey][$observation->finalPath] = true;
            }
        }

        $warnings = [];
        foreach ($observations as $observation) {
            if ($observation->finalStatus !== 200) {
                continue;
            }
            $identity = $observation->identity;
            if ($identity->hasRecord()) {
                $recordKey = implode('|', [$observation->environment, $identity->recordTable, $identity->recordUid, $identity->language]);
                // The same record rendered by more than one page is duplicate content.
                if (count($pageUidsByRecord[$recordKey] ?? []) > 1) {
                    $warnings[$observation->uid][] = self::WARNING_DUPLICATE_DETAIL_PAGE;
                }
            } elseif ($identity->hasPage()) {
                $pageKey = implode('|', [$observation->environment, $identity->pageUid, $identity->language]);
                // One page under several paths without a record marker is almost
                // always a detail page — every record would pass as "same page".
                if (count($finalPathsByPage[$pageKey] ?? []) > 1) {
                    $warnings[$observation->uid][] = self::WARNING_RECORD_IDENTITY_UNKNOWN;
                }
            }
        }

        return $warnings;
    }
}
