<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Exception;

/**
 * A sitemap import that cannot go on. The reason is a stable key, so the
 * backend can show a translated message while the CLI prints the English one.
 */
final class SitemapImportException extends \RuntimeException
{
    public const string REASON_LABEL_TOO_LONG = 'labelTooLong';
    public const string REASON_LABEL_EXISTS = 'labelExists';
    public const string REASON_SNAPSHOT_NOT_IMPORTING = 'snapshotNotImporting';
    public const string REASON_NO_URLS = 'noUrls';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
