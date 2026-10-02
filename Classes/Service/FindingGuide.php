<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

/**
 * Who acts on a finding, and which entry of the help page explains it.
 *
 * A finding is a verdict or warning of the migration check, or a marker or
 * failing status of the status check. "Editor" means it can be fixed in the
 * backend — a redirect record, a page property, a translation. "Integrator"
 * means it comes from the configuration, templates or the server and keeps
 * coming back until that changes. "None" means nothing has to be done.
 */
class FindingGuide
{
    public const string ACTOR_EDITOR = 'editor';
    public const string ACTOR_INTEGRATOR = 'integrator';
    public const string ACTOR_NONE = 'none';

    public const array ACTORS = [self::ACTOR_EDITOR, self::ACTOR_INTEGRATOR, self::ACTOR_NONE];

    /**
     * @var array<string, array{0: string, 1: string}> verdict => [actor, help entry]
     */
    private const array VERDICTS = [
        MigrationAnalyzer::VERDICT_REFERENCE => [self::ACTOR_NONE, ''],
        MigrationAnalyzer::VERDICT_LISTED => [self::ACTOR_NONE, ''],
        MigrationAnalyzer::VERDICT_OK => [self::ACTOR_NONE, ''],
        MigrationAnalyzer::VERDICT_MOVED_WITH_REDIRECT => [self::ACTOR_NONE, ''],
        MigrationAnalyzer::VERDICT_MISSING => [self::ACTOR_EDITOR, 'missing'],
        MigrationAnalyzer::VERDICT_REDIRECT_BROKEN => [self::ACTOR_EDITOR, 'redirectBroken'],
        MigrationAnalyzer::VERDICT_OTHER_CONTENT => [self::ACTOR_EDITOR, 'otherContent'],
        MigrationAnalyzer::VERDICT_REDIRECT_NOT_FINAL => [self::ACTOR_EDITOR, 'redirectNotFinal'],
        MigrationAnalyzer::VERDICT_IDENTITY_UNKNOWN => [self::ACTOR_INTEGRATOR, 'markers'],
        MigrationAnalyzer::VERDICT_TIMEOUT => [self::ACTOR_INTEGRATOR, 'timeout'],
        MigrationAnalyzer::VERDICT_REFERENCE_NOT_OK => [self::ACTOR_NONE, 'referenceNotOk'],
        MigrationAnalyzer::VERDICT_DETAIL_PAGE_WITHOUT_RECORD => [self::ACTOR_INTEGRATOR, 'detailPage'],
    ];

    /**
     * @var array<string, array{0: string, 1: string}> warning => [actor, help entry]
     */
    private const array WARNINGS = [
        MigrationAnalyzer::WARNING_REDIRECT_CHAIN => [self::ACTOR_EDITOR, 'redirectNotFinal'],
        MigrationAnalyzer::WARNING_SHORTCUT_IN_CHAIN => [self::ACTOR_EDITOR, 'redirectNotFinal'],
        MigrationAnalyzer::WARNING_CANONICAL_DIFFERS => [self::ACTOR_EDITOR, 'canonical'],
        MigrationAnalyzer::WARNING_TEMPORARY_REDIRECT => [self::ACTOR_EDITOR, 'temporaryRedirect'],
        MigrationAnalyzer::WARNING_REDIRECT_TO_ROOT_PAGE => [self::ACTOR_EDITOR, 'otherContent'],
        MigrationAnalyzer::WARNING_LANGUAGE_CHANGED => [self::ACTOR_EDITOR, 'languageChanged'],
        MigrationAnalyzer::WARNING_LISTED_URL_REDIRECTS => [self::ACTOR_INTEGRATOR, 'sitemapContent'],
        MigrationAnalyzer::WARNING_LISTED_URL_NOT_CANONICAL => [self::ACTOR_INTEGRATOR, 'canonical'],
        MigrationAnalyzer::WARNING_LISTED_DETAIL_PAGE_WITHOUT_RECORD => [self::ACTOR_INTEGRATOR, 'detailPage'],
        MigrationAnalyzer::WARNING_PAGE_ALSO_RENDERS_RECORDS => [self::ACTOR_INTEGRATOR, 'detailPage'],
        MigrationAnalyzer::WARNING_RECORD_IDENTITY_UNKNOWN => [self::ACTOR_INTEGRATOR, 'markers'],
        MigrationAnalyzer::WARNING_DUPLICATE_DETAIL_PAGE => [self::ACTOR_INTEGRATOR, 'duplicateDetailPage'],
    ];

    /**
     * @var array<string, array{0: string, 1: string}> marker => [actor, help entry]
     */
    private const array MARKERS = [
        'pageNotFound' => [self::ACTOR_EDITOR, 'errorPage'],
        'accessDenied' => [self::ACTOR_EDITOR, 'errorPage'],
        'productionException' => [self::ACTOR_INTEGRATOR, 'errorPage'],
        'developmentException' => [self::ACTOR_INTEGRATOR, 'errorPage'],
        ErrorMarkerDetector::MARKER_CONNECTION_ERROR => [self::ACTOR_INTEGRATOR, 'timeout'],
        ErrorMarkerDetector::MARKER_TIMEOUT => [self::ACTOR_INTEGRATOR, 'timeout'],
        ErrorMarkerDetector::MARKER_TOO_MANY_REDIRECTS => [self::ACTOR_INTEGRATOR, 'redirectNotFinal'],
        ErrorMarkerDetector::MARKER_RESPONSE_TOO_LARGE => [self::ACTOR_INTEGRATOR, 'errorPage'],
        ErrorMarkerDetector::MARKER_REDIRECTED => [self::ACTOR_INTEGRATOR, 'sitemapContent'],
        ErrorMarkerDetector::MARKER_REDIRECT_CHAIN => [self::ACTOR_INTEGRATOR, 'redirectNotFinal'],
        ErrorMarkerDetector::MARKER_CANONICAL_ELSEWHERE => [self::ACTOR_INTEGRATOR, 'canonical'],
        ErrorMarkerDetector::MARKER_DETAIL_PAGE_WITHOUT_RECORD => [self::ACTOR_INTEGRATOR, 'detailPage'],
        'argumentsIgnored' => [self::ACTOR_INTEGRATOR, 'argumentsIgnored'],
    ];

    /**
     * Statuses of a page that is simply not there: usually removed, hidden or
     * not translated — something an editor can change.
     */
    public const array NOT_FOUND_STATUSES = [404, 410];

    /**
     * @return array{actor: string, entry: string}
     */
    public function forVerdict(string $verdict): array
    {
        [$actor, $entry] = self::VERDICTS[$verdict] ?? [self::ACTOR_NONE, ''];

        return ['actor' => $actor, 'entry' => $entry];
    }

    /**
     * @return array{actor: string, entry: string}
     */
    public function forWarning(string $warning): array
    {
        [$actor, $entry] = self::WARNINGS[$warning] ?? [self::ACTOR_INTEGRATOR, ''];

        return ['actor' => $actor, 'entry' => $entry];
    }

    /**
     * A result of the status check: its marker, or — without one — its
     * status. Any other marker is the class name of an uncaught exception.
     *
     * @return array{actor: string, entry: string}
     */
    public function forStatusResult(string $marker, int $httpStatus): array
    {
        if ($marker !== '') {
            [$actor, $entry] = self::MARKERS[$marker] ?? [self::ACTOR_INTEGRATOR, 'errorPage'];
        } elseif ($httpStatus === 200) {
            [$actor, $entry] = [self::ACTOR_NONE, ''];
        } elseif (in_array($httpStatus, self::NOT_FOUND_STATUSES, true)) {
            [$actor, $entry] = [self::ACTOR_EDITOR, 'errorPage'];
        } else {
            [$actor, $entry] = [self::ACTOR_INTEGRATOR, 'errorPage'];
        }

        return ['actor' => $actor, 'entry' => $entry];
    }

    /**
     * A marker this extension does not define is the class name of an
     * uncaught exception, see ErrorMarkerDetector.
     */
    public function isExceptionMarker(string $marker): bool
    {
        return $marker !== '' && !isset(self::MARKERS[$marker]);
    }

    /**
     * Of the given markers, those an actor acts on.
     *
     * @param list<string> $markers
     * @return list<string>
     */
    public function filterMarkers(array $markers, string $actor): array
    {
        return array_values(array_filter(
            $markers,
            fn(string $marker): bool => $this->forStatusResult($marker, 200)['actor'] === $actor,
        ));
    }
}
