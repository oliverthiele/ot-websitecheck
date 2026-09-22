<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Domain\ValueObject;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\TransferFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TransferFailureTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{0: \Throwable, 1: TransferFailure}>
     */
    public static function throwables(): iterable
    {
        $request = new Request('GET', 'https://www.example.com/');

        yield 'no answer within the timeout' => [new NetworkTimeoutException('', $request), TransferFailure::Timeout];
        yield 'body stalled after the headers' => [new ResponseTimeoutException('', $request, new Response(200)), TransferFailure::Timeout];
        yield 'connection refused' => [new ConnectException('', $request), TransferFailure::Network];
        yield 'connect timeout is unreachable, not slow' => [new ConnectTimeoutException('', $request), TransferFailure::Network];
        yield 'connection reset' => [new NetworkException('', $request), TransferFailure::Network];
        yield 'too many redirects' => [new TooManyRedirectsException('', $request, new Response(301)), TransferFailure::Request];
        yield 'invalid request' => [new RequestException('', $request), TransferFailure::Request];
        yield 'anything else' => [new \RuntimeException(''), TransferFailure::Request];
    }

    #[Test]
    #[DataProvider('throwables')]
    public function throwableIsClassifiedByTransportPhase(\Throwable $throwable, TransferFailure $expected): void
    {
        self::assertSame($expected, TransferFailure::fromThrowable($throwable));
    }

    #[Test]
    public function onlyTransportFailuresAreRetryable(): void
    {
        self::assertTrue(TransferFailure::Timeout->isRetryable());
        self::assertTrue(TransferFailure::Network->isRetryable());
        self::assertFalse(TransferFailure::Request->isRetryable());
    }
}
