<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

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
    public const string VERDICT_REDIRECT_NOT_FINAL = 'redirectNotFinal';
    public const string VERDICT_MISSING = 'missing';
    public const string VERDICT_REDIRECT_BROKEN = 'redirectBroken';
    public const string VERDICT_OTHER_CONTENT = 'otherContent';
    public const string VERDICT_IDENTITY_UNKNOWN = 'identityUnknown';
    public const string VERDICT_TIMEOUT = 'timeout';
    public const string VERDICT_LISTED = 'listed';
    public const string VERDICT_DETAIL_PAGE_WITHOUT_RECORD = 'detailPageWithoutRecord';

    /**
     * Verdicts on a target row that need attention.
     */
    public const array PROBLEM_VERDICTS = [
        self::VERDICT_MISSING,
        self::VERDICT_REDIRECT_BROKEN,
        self::VERDICT_OTHER_CONTENT,
        self::VERDICT_IDENTITY_UNKNOWN,
        self::VERDICT_TIMEOUT,
        self::VERDICT_REDIRECT_NOT_FINAL,
    ];

    public const string WARNING_REDIRECT_CHAIN = 'redirectChain';
    public const string WARNING_TEMPORARY_REDIRECT = 'temporaryRedirect';
    public const string WARNING_REDIRECT_TO_ROOT_PAGE = 'redirectToRootPage';
    public const string WARNING_LISTED_URL_REDIRECTS = 'listedUrlRedirects';
    public const string WARNING_LANGUAGE_CHANGED = 'languageChanged';
    public const string WARNING_RECORD_IDENTITY_UNKNOWN = 'recordIdentityUnknown';
    public const string WARNING_DUPLICATE_DETAIL_PAGE = 'duplicateDetailPage';
    public const string WARNING_SHORTCUT_IN_CHAIN = 'shortcutInChain';
    public const string WARNING_CANONICAL_DIFFERS = 'canonicalDiffers';
    public const string WARNING_LISTED_URL_NOT_CANONICAL = 'listedUrlNotCanonical';
    public const string WARNING_LISTED_DETAIL_PAGE_WITHOUT_RECORD = 'listedDetailPageWithoutRecord';
    public const string WARNING_PAGE_ALSO_RENDERS_RECORDS = 'pageAlsoRendersRecords';

    private const array TEMPORARY_REDIRECT_STATUS_CODES = [302, 303, 307];

    /**
     * X-Redirect-By of a redirect TYPO3 makes for a shortcut or mount point page.
     */
    private const string SHORTCUT_REDIRECT_BY = 'TYPO3 Shortcut/Mountpoint';

    /**
     * Target verdicts a detail page called without a record replaces: whatever
     * such a URL answers, there is no page of its own to keep.
     */
    private const array VERDICTS_OF_A_DETAIL_PAGE_WITHOUT_RECORD = [
        self::VERDICT_MISSING,
        self::VERDICT_REDIRECT_BROKEN,
        self::VERDICT_OTHER_CONTENT,
        self::VERDICT_IDENTITY_UNKNOWN,
        self::VERDICT_REFERENCE_NOT_OK,
    ];

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
     * @param array<int, true> $detailPagesWithoutRecord uids of the rows that call a page marked as requiring a
     *                                                    parameter without one, see RequiredParameterPages
     * @return array<int, array{verdict: string, warnings: list<string>, suggestedTarget: string}> Keyed by observation uid.
     */
    public function analyze(array $observations, array $detailPagesWithoutRecord = []): array
    {
        $referenceByPath = [];
        $workingReferenceByFinalPath = [];
        $candidatesOnTarget = [];
        foreach ($observations as $observation) {
            if ($observation->role === Observation::ROLE_REFERENCE) {
                $referenceByPath[$observation->requestedPath] = $observation;
                if ($observation->finalStatus === 200) {
                    $workingReferenceByFinalPath[UrlUtility::comparablePath($observation->finalPath)] = $observation;
                }
            }
            if (in_array($observation->role, [Observation::ROLE_TARGET, Observation::ROLE_TARGET_SITEMAP], true)
                && $observation->finalStatus === 200
            ) {
                $candidatesOnTarget[] = $observation;
            }
        }

        $crossRowWarnings = $this->collectCrossRowWarnings($observations, $detailPagesWithoutRecord);

        $results = [];
        foreach ($observations as $observation) {
            $warnings = $this->collectChainWarnings($observation);
            $suggestedTarget = '';

            if ($observation->role === Observation::ROLE_REFERENCE) {
                $verdict = $observation->finalStatus === 200 ? self::VERDICT_REFERENCE : self::VERDICT_REFERENCE_NOT_OK;
                $warnings = [...$warnings, ...$this->collectListingWarnings($observation, $detailPagesWithoutRecord)];
            } elseif ($observation->role === Observation::ROLE_TARGET) {
                $reference = $referenceByPath[$observation->requestedPath] ?? null;
                $canonicalReference = $reference === null ? null : $this->findCanonicalReference($reference, $workingReferenceByFinalPath);
                $verdict = $reference === null ? '' : $this->resolveTargetVerdict($reference, $observation, $canonicalReference);
                if ($reference !== null && $this->isLanguageChanged($reference->identity, $observation->identity)) {
                    $warnings[] = self::WARNING_LANGUAGE_CHANGED;
                }
                if ($reference !== null && in_array($verdict, [self::VERDICT_MISSING, self::VERDICT_REDIRECT_BROKEN, self::VERDICT_OTHER_CONTENT], true)) {
                    $suggestedTarget = $this->findSuggestedTarget($reference, $canonicalReference, $observation, $candidatesOnTarget);
                }
                if ($reference !== null
                    && (isset($detailPagesWithoutRecord[$reference->uid]) || isset($detailPagesWithoutRecord[$observation->uid]))
                    && in_array($verdict, self::VERDICTS_OF_A_DETAIL_PAGE_WITHOUT_RECORD, true)
                ) {
                    $verdict = self::VERDICT_DETAIL_PAGE_WITHOUT_RECORD;
                    $suggestedTarget = '';
                }
                if ($verdict === self::VERDICT_REDIRECT_NOT_FINAL) {
                    if ($this->declaresCanonicalElsewhere($observation)) {
                        $warnings[] = self::WARNING_CANONICAL_DIFFERS;
                        $suggestedTarget = UrlUtility::comparablePath($observation->canonicalUrl);
                    } else {
                        $suggestedTarget = $observation->finalPath;
                    }
                }
            } elseif ($observation->role === Observation::ROLE_TARGET_SITEMAP) {
                $verdict = self::VERDICT_LISTED;
                $warnings = [...$warnings, ...$this->collectListingWarnings($observation, $detailPagesWithoutRecord)];
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

    /**
     * @param Observation|null $canonicalReference the reference row of the page the reference declares as canonical, see findCanonicalReference()
     */
    private function resolveTargetVerdict(Observation $reference, Observation $target, ?Observation $canonicalReference): string
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
        // The page answered, but too much to read the markers out of it.
        if ($target->abortReason === RedirectChain::ABORT_RESPONSE_TOO_LARGE) {
            return self::VERDICT_IDENTITY_UNKNOWN;
        }
        if ($target->finalStatus !== 200) {
            return $target->hopCount > 0 ? self::VERDICT_REDIRECT_BROKEN : self::VERDICT_MISSING;
        }

        $sameContent = $this->isSameContent($reference->identity, $target->identity);
        // A page that shows the content of another one declares that page as
        // canonical. A redirect straight to it leads to the same content.
        if ($sameContent !== true
            && $canonicalReference !== null
            && $this->isSameContent($canonicalReference->identity, $target->identity) === true
        ) {
            $sameContent = true;
        }
        if ($sameContent === null) {
            return self::VERDICT_IDENTITY_UNKNOWN;
        }
        if ($sameContent === false) {
            return self::VERDICT_OTHER_CONTENT;
        }
        if ($target->hopCount === 0) {
            return self::VERDICT_OK;
        }

        // Right content, but the redirect should lead there in one step.
        return $target->hopCount > 1 || $this->declaresCanonicalElsewhere($target)
            ? self::VERDICT_REDIRECT_NOT_FINAL
            : self::VERDICT_MOVED_WITH_REDIRECT;
    }

    /**
     * The reference row of the page a reference declares as canonical, when
     * that is another page of the run — e.g. the page whose content a page
     * shows with "Show Content from this page".
     *
     * @param array<string, Observation> $workingReferenceByFinalPath comparable final path => reference row answering 200
     */
    private function findCanonicalReference(Observation $reference, array $workingReferenceByFinalPath): ?Observation
    {
        if (!$this->declaresCanonicalElsewhere($reference)) {
            return null;
        }

        return $workingReferenceByFinalPath[UrlUtility::comparablePath($reference->canonicalUrl)] ?? null;
    }

    /**
     * A working page whose canonical names another path than its own.
     */
    private function declaresCanonicalElsewhere(Observation $observation): bool
    {
        return $observation->finalStatus === 200
            && $observation->canonicalUrl !== ''
            && UrlUtility::comparablePath($observation->canonicalUrl) !== UrlUtility::comparablePath($observation->finalPath);
    }

    /**
     * What a URL listed in a sitemap should not do: redirect, or name another
     * URL as canonical. A sitemap lists the URLs a search engine should index.
     *
     * @param array<int, true> $detailPagesWithoutRecord
     * @return list<string>
     */
    private function collectListingWarnings(Observation $observation, array $detailPagesWithoutRecord): array
    {
        if (isset($detailPagesWithoutRecord[$observation->uid])) {
            return [self::WARNING_LISTED_DETAIL_PAGE_WITHOUT_RECORD];
        }
        if ($observation->hopCount > 0) {
            return [self::WARNING_LISTED_URL_REDIRECTS];
        }

        return $this->declaresCanonicalElsewhere($observation) ? [self::WARNING_LISTED_URL_NOT_CANONICAL] : [];
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
     * The page the reference declares as canonical comes first: that is where
     * a redirect should end. A candidate that declares another page as
     * canonical is no final target itself and is left out.
     *
     * @param list<Observation> $candidates
     */
    private function findSuggestedTarget(Observation $reference, ?Observation $canonicalReference, Observation $target, array $candidates): string
    {
        $finalCandidates = array_filter(
            $candidates,
            fn(Observation $candidate): bool => $candidate->uid !== $target->uid && !$this->declaresCanonicalElsewhere($candidate),
        );
        if ($canonicalReference !== null) {
            $suggestion = $this->findUnambiguousPath($canonicalReference->identity, $finalCandidates);
            if ($suggestion !== '') {
                return $suggestion;
            }
        }

        return $this->findUnambiguousPath($reference->identity, $finalCandidates);
    }

    /**
     * @param array<int, Observation> $candidates
     */
    private function findUnambiguousPath(PageIdentity $identity, array $candidates): string
    {
        if (!$identity->hasPage() && !$identity->hasRecord()) {
            return '';
        }
        $matchingPaths = [];
        foreach ($candidates as $candidate) {
            if ($this->isSameContent($identity, $candidate->identity) === true) {
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
        // A redirect that leads to a shortcut page, which redirects again. The
        // requested URL being a shortcut itself is a single redirect.
        foreach (array_slice($redirectSteps, 1) as $step) {
            if (str_starts_with($step['redirectBy'] ?? '', self::SHORTCUT_REDIRECT_BY)) {
                $warnings[] = self::WARNING_SHORTCUT_IN_CHAIN;
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
     * @param array<int, true> $detailPagesWithoutRecord
     * @return array<int, list<string>> Keyed by observation uid.
     */
    private function collectCrossRowWarnings(array $observations, array $detailPagesWithoutRecord): array
    {
        $finalPathsByPage = [];
        $pageUidsByRecord = [];
        $pagesRenderingRecords = [];
        foreach ($observations as $observation) {
            if ($observation->finalStatus !== 200) {
                continue;
            }
            $identity = $observation->identity;
            if ($identity->hasRecord()) {
                $recordKey = implode('|', [$observation->environment, $identity->recordTable, $identity->recordUid, $identity->language]);
                $pageUidsByRecord[$recordKey][$identity->pageUid] = true;
                $pagesRenderingRecords[$observation->environment . '|' . $identity->pageUid] = true;
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
                // The page renders records under other URLs: this URL may call
                // a detail page without one — or a list on the same page, so
                // only a hint. A page marked as requiring a parameter is certain.
                if ($observation->role !== Observation::ROLE_TARGET
                    && !isset($detailPagesWithoutRecord[$observation->uid])
                    && isset($pagesRenderingRecords[$observation->environment . '|' . $identity->pageUid])
                ) {
                    $warnings[$observation->uid][] = self::WARNING_PAGE_ALSO_RENDERS_RECORDS;
                }
            }
        }

        return $warnings;
    }
}
