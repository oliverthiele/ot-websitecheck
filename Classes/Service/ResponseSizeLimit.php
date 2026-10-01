<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Exception\ResponseTooLargeException;

/**
 * Caps every response body this extension reads, so a sitemap or page of
 * any size cannot exhaust the memory of the process. 50 MiB is the largest
 * sitemap file the sitemap protocol allows, uncompressed; no page comes near.
 */
final class ResponseSizeLimit
{
    public const int MAXIMUM_BYTES = 50 * 1024 * 1024;

    /**
     * @return array{progress: \Closure(int, int): void} Guzzle request options
     */
    public static function requestOptions(): array
    {
        return [
            'progress' => static function (int $downloadTotal, int $downloaded): void {
                if ($downloadTotal > self::MAXIMUM_BYTES || $downloaded > self::MAXIMUM_BYTES) {
                    throw new ResponseTooLargeException(sprintf('The response exceeds %d bytes.', self::MAXIMUM_BYTES), 1790850001);
                }
            },
        ];
    }
}
