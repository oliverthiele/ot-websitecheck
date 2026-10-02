<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use GuzzleHttp\RedirectMiddleware;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\TransferFailure;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Requests one URL and keeps status and body, whatever the status is — an
 * error page is a result here, not an exception. Redirects are followed and
 * counted, so a URL that only works through a redirect stays visible.
 *
 * One attempt per call. Whether a failed transfer is worth another one is up
 * to the caller, see RetryRounds.
 */
class PageFetcher
{
    public const int MAXIMUM_REDIRECTS = 10;

    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $requestOptions Additional Guzzle options, e.g. ['auth' => ['user', 'pass']].
     */
    public function fetch(string $url, int $timeout, array $requestOptions = []): FetchedPage
    {
        try {
            $response = $this->requestFactory->request($url, 'GET', $requestOptions + ResponseSizeLimit::requestOptions() + [
                'timeout' => $timeout,
                'http_errors' => false,
                'allow_redirects' => [
                    'max' => self::MAXIMUM_REDIRECTS,
                    'track_redirects' => true,
                ],
            ]);
        } catch (\Throwable $throwable) {
            return new FetchedPage(0, '', TransferFailure::fromThrowable($throwable));
        }

        $redirectHistory = $response->getHeader(RedirectMiddleware::HISTORY_HEADER);

        return new FetchedPage(
            $response->getStatusCode(),
            (string)$response->getBody(),
            null,
            count($redirectHistory),
            $redirectHistory === [] ? '' : (string)end($redirectHistory),
        );
    }
}
