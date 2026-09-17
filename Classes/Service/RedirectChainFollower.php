<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Follows redirects one request at a time instead of letting Guzzle do it, so
 * every hop is recorded with its status code. Whether a redirect comes from the
 * webserver or from TYPO3 makes no difference here — only the HTTP answers count.
 */
class RedirectChainFollower
{
    private const array REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $requestOptions Guzzle options. An "auth" entry is only sent to the host of $url,
     *                                             never to a host a redirect leads to.
     */
    public function follow(string $url, int $timeout, int $maximumHops, array $requestOptions = []): RedirectChain
    {
        $initialHost = parse_url($url, PHP_URL_HOST);
        $steps = [];
        $visitedUrls = [];
        $currentUrl = $url;

        while (true) {
            if (isset($visitedUrls[$currentUrl])) {
                return new RedirectChain($steps, '', RedirectChain::ABORT_LOOP);
            }
            $visitedUrls[$currentUrl] = true;

            $options = $requestOptions;
            if (parse_url($currentUrl, PHP_URL_HOST) !== $initialHost) {
                unset($options['auth']);
            }

            try {
                $response = $this->requestFactory->request($currentUrl, 'GET', [
                    'timeout' => $timeout,
                    'allow_redirects' => false,
                    'http_errors' => false,
                ] + $options);
            } catch (\Throwable) {
                $steps[] = ['url' => $currentUrl, 'status' => 0];
                return new RedirectChain($steps, '', RedirectChain::ABORT_CONNECTION_ERROR);
            }

            $status = $response->getStatusCode();
            $steps[] = ['url' => $currentUrl, 'status' => $status];

            if (!in_array($status, self::REDIRECT_STATUS_CODES, true)) {
                return new RedirectChain($steps, (string)$response->getBody());
            }

            $location = $response->getHeaderLine('Location');
            if ($location === '') {
                return new RedirectChain($steps, '', RedirectChain::ABORT_MISSING_LOCATION);
            }
            if (count($steps) > $maximumHops) {
                return new RedirectChain($steps, '', RedirectChain::ABORT_HOP_LIMIT);
            }

            // Location may be relative ("/new-path/"), which is valid since RFC 7231.
            $currentUrl = (string)UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        }
    }
}
