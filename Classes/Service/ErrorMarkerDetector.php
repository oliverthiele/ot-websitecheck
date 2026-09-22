<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;

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

    /**
     * The marker of a fetched page: a failed connection is a marker of its own,
     * and a timeout one apart from it — a slow page is not a broken one.
     */
    public function detectFor(FetchedPage $page): string
    {
        if ($page->isTimeout()) {
            return self::MARKER_TIMEOUT;
        }

        return $page->isConnectionError() ? self::MARKER_CONNECTION_ERROR : $this->detect($page->body);
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
