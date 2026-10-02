<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Utility;

use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;

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

    /**
     * Path and query for comparing two URLs as pages: "https://www.example.com"
     * and "https://www.example.com/" are the same page.
     */
    public static function comparablePath(string $url): string
    {
        $path = self::pathWithQuery($url);

        return $path === '' || str_starts_with($path, '?') ? '/' . $path : $path;
    }

    /**
     * Whether credentials given for $authorizedUrl may be sent to $url: same
     * host, same port, and no step down from https to http. The rule Guzzle
     * applies to its own redirects; an upgrade to https keeps the credentials,
     * since a staging site commonly redirects there first.
     */
    public static function sharesCredentials(string $authorizedUrl, string $url): bool
    {
        try {
            $authorized = new Uri($authorizedUrl);
            $candidate = new Uri($url);
        } catch (MalformedUriException) {
            return false;
        }
        if ($authorized->getHost() === '' || strtolower($authorized->getHost()) !== strtolower($candidate->getHost())) {
            return false;
        }
        if ($authorized->getPort() !== $candidate->getPort()) {
            return false;
        }

        return !(strtolower($authorized->getScheme()) === 'https' && strtolower($candidate->getScheme()) !== 'https');
    }

    /**
     * Returns $requestOptions without their "auth" entry unless $url shares
     * credentials with one of $authorizedUrls — the URLs the credentials were
     * given for. URLs read from a remote document (a sitemap index, a stored
     * snapshot) never get credentials on their own account.
     *
     * @param array<string, mixed> $requestOptions
     * @param list<string> $authorizedUrls
     * @return array<string, mixed>
     */
    public static function requestOptionsFor(array $requestOptions, string $url, array $authorizedUrls): array
    {
        if (!isset($requestOptions['auth'])) {
            return $requestOptions;
        }
        foreach ($authorizedUrls as $authorizedUrl) {
            if (self::sharesCredentials($authorizedUrl, $url)) {
                return $requestOptions;
            }
        }
        unset($requestOptions['auth']);

        return $requestOptions;
    }
}
