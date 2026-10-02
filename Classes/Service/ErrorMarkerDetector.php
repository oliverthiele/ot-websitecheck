<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\TransferFailure;

/**
 * Detects the typical HTML markers TYPO3 leaves behind when a page fails,
 * both in production ("Oops, an error occurred!") and development
 * (uncaught exception with stack trace) context.
 */
class ErrorMarkerDetector
{
    /**
     * @var array<string, string>
     */
    private const array MARKER_PATTERNS = [
        'productionException' => '/Oops, an error occurred/i',
        'developmentException' => '/\(1\/\d+\)\s*#\d+/',
        'pageNotFound' => '/The page did not exist or was inaccessible/i',
        'accessDenied' => '/Reason: (?:Subsection|ID was not an accessible page)/i',
    ];

    public const string MARKER_CONNECTION_ERROR = 'connectionError';
    public const string MARKER_TIMEOUT = 'timeout';
    public const string MARKER_TOO_MANY_REDIRECTS = 'tooManyRedirects';
    public const string MARKER_RESPONSE_TOO_LARGE = 'responseTooLarge';
    public const string MARKER_REDIRECTED = 'redirected';
    public const string MARKER_REDIRECT_CHAIN = 'redirectChain';
    public const string MARKER_CANONICAL_ELSEWHERE = 'canonicalElsewhere';

    /**
     * Markers of a page that works: they point at something to tidy up, not at
     * a broken page, and do not fail a run with --fail-on-problems.
     */
    public const array NOTICE_MARKERS = [
        self::MARKER_REDIRECTED,
        self::MARKER_REDIRECT_CHAIN,
        self::MARKER_CANONICAL_ELSEWHERE,
    ];

    /**
     * The marker of a fetched page: a failed connection is a marker of its own,
     * and a timeout one apart from it — a slow page is not a broken one. A page
     * reached only through a redirect is marked as such unless its body shows
     * an error; the status is that of the final page.
     *
     * @param bool $canonicalElsewhere the page answers directly and names another URL as canonical
     */
    public function detectFor(FetchedPage $page, bool $canonicalElsewhere = false): string
    {
        $marker = match ($page->transferFailure) {
            TransferFailure::Timeout => self::MARKER_TIMEOUT,
            TransferFailure::TooManyRedirects => self::MARKER_TOO_MANY_REDIRECTS,
            TransferFailure::TooLarge => self::MARKER_RESPONSE_TOO_LARGE,
            null => $page->isConnectionError() ? self::MARKER_CONNECTION_ERROR : $this->detect($page->body),
            default => self::MARKER_CONNECTION_ERROR,
        };

        if ($marker !== '') {
            return $marker;
        }

        return match (true) {
            $page->redirectCount > 1 => self::MARKER_REDIRECT_CHAIN,
            $page->isRedirected() => self::MARKER_REDIRECTED,
            $canonicalElsewhere && $page->isOk() => self::MARKER_CANONICAL_ELSEWHERE,
            default => '',
        };
    }

    public function detect(string $html): string
    {
        $exceptionClass = $this->detectExceptionClass($html);
        if ($exceptionClass !== null) {
            return $exceptionClass;
        }

        foreach (self::MARKER_PATTERNS as $marker => $pattern) {
            if (preg_match($pattern, $html) === 1) {
                return $marker;
            }
        }

        return '';
    }

    private function detectExceptionClass(string $html): ?string
    {
        if (preg_match('/#\d+\s+([A-Za-z0-9_\\\\]+Exception)\b/', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
