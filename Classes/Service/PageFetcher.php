<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Requests one URL and keeps status and body, whatever the status is — an
 * error page is a result here, not an exception.
 */
class PageFetcher
{
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
            $response = $this->requestFactory->request($url, 'GET', $requestOptions + [
                'timeout' => $timeout,
                'http_errors' => false,
            ]);
        } catch (\Throwable) {
            return new FetchedPage(0, '');
        }

        return new FetchedPage($response->getStatusCode(), (string)$response->getBody());
    }
}
