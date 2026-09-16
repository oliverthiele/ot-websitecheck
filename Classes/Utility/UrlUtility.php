<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Utility;

final class UrlUtility
{
    /**
     * Path + query, without scheme and host — the part that stays comparable
     * across environments whose URLs otherwise only differ by host.
     */
    public static function pathWithQuery(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }
}
