<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Service\RedirectChainFollower;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class RedirectChainFollowerTest extends UnitTestCase
{
    #[Test]
    public function relativeLocationIsResolvedAgainstTheCurrentUrl(): void
    {
        $subject = $this->followerAnswering([
            'https://www.example.com/old/' => [301, '/new/'],
            'https://www.example.com/new/' => [200, ''],
        ]);

        $chain = $subject->follow('https://www.example.com/old/', 5, 10);

        self::assertSame('https://www.example.com/new/', $chain->getFinalUrl());
        self::assertSame(200, $chain->getFinalStatus());
        self::assertSame(1, $chain->getHopCount());
    }

    #[Test]
    public function loopIsAbortedAndNeverPassesAsWorkingPage(): void
    {
        $subject = $this->followerAnswering([
            'https://www.example.com/a' => [301, '/b'],
            'https://www.example.com/b' => [301, '/a'],
        ]);

        $chain = $subject->follow('https://www.example.com/a', 5, 10);

        self::assertSame(RedirectChain::ABORT_LOOP, $chain->abortReason);
        self::assertSame(0, $chain->getFinalStatus());
    }

    #[Test]
    public function hopLimitIsAborted(): void
    {
        $answers = [];
        for ($i = 0; $i < 5; $i++) {
            $answers['https://www.example.com/' . $i] = [301, '/' . ($i + 1)];
        }
        $subject = $this->followerAnswering($answers);

        $chain = $subject->follow('https://www.example.com/0', 5, 2);

        self::assertSame(RedirectChain::ABORT_HOP_LIMIT, $chain->abortReason);
    }

    #[Test]
    public function timeoutOnAHopAbortsAsRetryableTimeout(): void
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            static fn(string $url): Response => $url === 'https://www.example.com/old/'
                ? new Response('php://temp', 301, ['Location' => '/slow/'])
                : throw new NetworkTimeoutException('cURL error 28', new Request('GET', $url)),
        );

        $chain = (new RedirectChainFollower($requestFactory))->follow('https://www.example.com/old/', 5, 10);

        self::assertSame(RedirectChain::ABORT_TIMEOUT, $chain->abortReason);
        self::assertTrue($chain->isRetryable());
        self::assertSame(0, $chain->getFinalStatus());
        self::assertSame(1, $chain->getHopCount());
    }

    #[Test]
    public function refusedConnectionAbortsAsRetryableConnectionError(): void
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new ConnectException('cURL error 7', new Request('GET', 'https://www.example.com/')));

        $chain = (new RedirectChainFollower($requestFactory))->follow('https://www.example.com/', 5, 10);

        self::assertSame(RedirectChain::ABORT_CONNECTION_ERROR, $chain->abortReason);
        self::assertTrue($chain->isRetryable());
    }

    #[Test]
    public function serverErrorEndsTheChainAndIsNotRetryable(): void
    {
        $chain = $this->followerAnswering(['https://www.example.com/' => [500, '']])->follow('https://www.example.com/', 5, 10);

        self::assertSame(RedirectChain::ABORT_NONE, $chain->abortReason);
        self::assertSame(500, $chain->getFinalStatus());
        self::assertFalse($chain->isRetryable());
    }

    #[Test]
    public function basicAuthIsNotSentToAnotherHost(): void
    {
        $sentOptions = [];
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            static function (string $url, string $method, array $options) use (&$sentOptions): Response {
                $sentOptions[$url] = $options;
                return $url === 'https://www.example.com/'
                    ? new Response('php://temp', 301, ['Location' => 'https://other.example.org/'])
                    : new Response('php://temp', 200);
            },
        );

        (new RedirectChainFollower($requestFactory))->follow('https://www.example.com/', 5, 10, ['auth' => ['user', 'secret']]);

        self::assertArrayHasKey('auth', $sentOptions['https://www.example.com/']);
        self::assertArrayNotHasKey('auth', $sentOptions['https://other.example.org/']);
    }

    /**
     * @param array<string, array{0: int, 1: string}> $answers URL => [status, Location header]
     */
    private function followerAnswering(array $answers): RedirectChainFollower
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            static function (string $url) use ($answers): Response {
                [$status, $location] = $answers[$url] ?? [404, ''];
                return new Response('php://temp', $status, $location !== '' ? ['Location' => $location] : []);
            },
        );

        return new RedirectChainFollower($requestFactory);
    }
}
