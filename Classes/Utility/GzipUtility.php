<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Utility;

final class GzipUtility
{
    /**
     * Inflates gzip data, refusing to produce more than $maximumBytes — a
     * small compressed file can expand to any size.
     *
     * @return string|null null when the data is no valid gzip or too large
     */
    public static function decode(string $compressed, int $maximumBytes): ?string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            return null;
        }
        $decoded = '';
        // Small chunks: deflate expands up to about 1000:1, so the check runs
        // before a single chunk can grow beyond a few megabytes.
        foreach (str_split($compressed, 8192) as $chunk) {
            $part = @inflate_add($context, $chunk, ZLIB_SYNC_FLUSH);
            if ($part === false) {
                return null;
            }
            $decoded .= $part;
            if (strlen($decoded) > $maximumBytes) {
                return null;
            }
        }
        $rest = @inflate_add($context, '', ZLIB_FINISH);
        if ($rest === false || strlen($decoded) + strlen($rest) > $maximumBytes) {
            return null;
        }

        return $decoded . $rest;
    }
}
