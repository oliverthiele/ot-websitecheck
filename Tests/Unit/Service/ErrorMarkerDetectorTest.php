<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\TransferFailure;
use OliverThiele\OtWebsitecheck\Service\ErrorMarkerDetector;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ErrorMarkerDetectorTest extends UnitTestCase
{
    #[Test]
    public function workingPageReachedThroughARedirectIsMarkedAsRedirected(): void
    {
        $page = new FetchedPage(200, '<html><body>Welcome</body></html>', null, 1);

        self::assertSame(ErrorMarkerDetector::MARKER_REDIRECTED, (new ErrorMarkerDetector())->detectFor($page));
    }

    #[Test]
    public function workingPageReachedThroughSeveralRedirectsIsMarkedAsChain(): void
    {
        $page = new FetchedPage(200, '<html><body>Welcome</body></html>', null, 2);

        self::assertSame(ErrorMarkerDetector::MARKER_REDIRECT_CHAIN, (new ErrorMarkerDetector())->detectFor($page));
    }

    #[Test]
    public function workingPageNamingAnotherCanonicalIsMarked(): void
    {
        $page = new FetchedPage(200, '<html><body>Welcome</body></html>');

        self::assertSame(ErrorMarkerDetector::MARKER_CANONICAL_ELSEWHERE, (new ErrorMarkerDetector())->detectFor($page, true));
    }

    #[Test]
    public function redirectAndErrorOutrankTheCanonical(): void
    {
        $detector = new ErrorMarkerDetector();

        self::assertSame(ErrorMarkerDetector::MARKER_REDIRECTED, $detector->detectFor(new FetchedPage(200, '', null, 1), true));
        self::assertSame('productionException', $detector->detectFor(new FetchedPage(200, '<h1>Oops, an error occurred!</h1>'), true));
        self::assertSame('', $detector->detectFor(new FetchedPage(404, ''), true));
    }

    #[Test]
    public function errorPageBehindARedirectKeepsItsErrorMarker(): void
    {
        $page = new FetchedPage(200, '<h1>Oops, an error occurred!</h1>', null, 2);

        self::assertSame('productionException', (new ErrorMarkerDetector())->detectFor($page));
    }

    #[Test]
    public function transferFailuresHaveMarkersOfTheirOwn(): void
    {
        $detector = new ErrorMarkerDetector();

        self::assertSame(ErrorMarkerDetector::MARKER_TIMEOUT, $detector->detectFor(new FetchedPage(0, '', TransferFailure::Timeout)));
        self::assertSame(ErrorMarkerDetector::MARKER_TOO_MANY_REDIRECTS, $detector->detectFor(new FetchedPage(0, '', TransferFailure::TooManyRedirects)));
        self::assertSame(ErrorMarkerDetector::MARKER_RESPONSE_TOO_LARGE, $detector->detectFor(new FetchedPage(0, '', TransferFailure::TooLarge)));
        self::assertSame(ErrorMarkerDetector::MARKER_CONNECTION_ERROR, $detector->detectFor(new FetchedPage(0, '', TransferFailure::Network)));
    }

    #[Test]
    public function workingPageWithoutRedirectHasNoMarker(): void
    {
        self::assertSame('', (new ErrorMarkerDetector())->detectFor(new FetchedPage(200, '<html><body>Welcome</body></html>')));
    }
}
