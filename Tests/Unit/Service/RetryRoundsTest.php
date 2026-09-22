<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use OliverThiele\OtWebsitecheck\Service\PageFetcher;
use OliverThiele\OtWebsitecheck\Service\RetryRounds;
use OliverThiele\OtWebsitecheck\Tests\Unit\Fixtures\FakeClock;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Runs the real PageFetcher against a mocked HTTP client, so the retry
 * decision is tested on what Guzzle actually throws — and on what it does not
 * throw: an HTTP error answer.
 */
final class RetryRoundsTest extends UnitTestCase
{
    private const string TIMEOUT = 'timeout';
    private const string REFUSED = 'refused';

    /**
     * @var list<string>
     */
    private array $requestedUrls = [];

    /**
     * @var list<int>
     */
    private array $pauses = [];

    /**
     * @var array<string, FetchedPage>
     */
    private array $results = [];

    /**
     * @var list<array{0: int, 1: int}>
     */
    private array $rounds = [];

    private FakeClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeClock();
    }

    #[Test]
    public function timedOutPageIsRequestedAgainAfterAllOthers(): void
    {
        $this->runRounds([
            'https://www.example.com/slow/' => [self::TIMEOUT, 200],
            'https://www.example.com/a/' => [200],
            'https://www.example.com/b/' => [200],
        ], 2);

        self::assertSame([
            'https://www.example.com/slow/',
            'https://www.example.com/a/',
            'https://www.example.com/b/',
            'https://www.example.com/slow/',
        ], $this->requestedUrls);
        self::assertSame(200, $this->results['https://www.example.com/slow/']->httpStatus);
        self::assertCount(3, $this->results);
        self::assertSame([[1, 3], [2, 1]], $this->rounds);
    }

    #[Test]
    public function serverErrorIsNotRequestedAgain(): void
    {
        $this->runRounds(['https://www.example.com/broken/' => [500, 200]], 2);

        self::assertSame(['https://www.example.com/broken/'], $this->requestedUrls);
        self::assertSame(500, $this->results['https://www.example.com/broken/']->httpStatus);
        self::assertFalse($this->results['https://www.example.com/broken/']->isTimeout());
    }

    #[Test]
    public function persistentTimeoutIsKeptAsTimeoutAfterAllRetries(): void
    {
        $this->runRounds(['https://www.example.com/slow/' => [self::TIMEOUT, self::TIMEOUT, self::TIMEOUT]], 2);

        self::assertCount(3, $this->requestedUrls);
        self::assertTrue($this->results['https://www.example.com/slow/']->isTimeout());
        self::assertSame(0, $this->results['https://www.example.com/slow/']->httpStatus);
    }

    #[Test]
    public function refusedConnectionIsRetriedAndKeptApartFromTimeout(): void
    {
        $this->runRounds(['https://www.example.com/' => [self::REFUSED, self::REFUSED]], 1);

        self::assertCount(2, $this->requestedUrls);
        self::assertTrue($this->results['https://www.example.com/']->isConnectionError());
        self::assertFalse($this->results['https://www.example.com/']->isTimeout());
    }

    #[Test]
    public function noRetriesMeansOneRequest(): void
    {
        $this->runRounds(['https://www.example.com/slow/' => [self::TIMEOUT, 200]], 0);

        self::assertCount(1, $this->requestedUrls);
        self::assertTrue($this->results['https://www.example.com/slow/']->isTimeout());
    }

    #[Test]
    public function shortRoundWaitsForTheMinimumGap(): void
    {
        $this->runRounds(['https://www.example.com/slow/' => [self::TIMEOUT, 200]], 2);

        // The gap counts from the end of the failed attempt; nothing else ran since.
        self::assertSame([5_000_000], $this->pauses);
    }

    #[Test]
    public function longRoundNeedsNoPause(): void
    {
        $answers = ['https://www.example.com/slow/' => [self::TIMEOUT, 200]];
        for ($i = 0; $i < 5; $i++) {
            $answers['https://www.example.com/' . $i . '/'] = [200];
        }

        $this->runRounds($answers, 2);

        self::assertSame([], $this->pauses);
    }

    #[Test]
    public function emptyListStillStartsOneRound(): void
    {
        $this->runRounds([], 2);

        self::assertSame([[1, 0]], $this->rounds);
        self::assertSame([], $this->results);
    }

    /**
     * @param array<string, list<int|string>> $answers URL => answer per attempt: a status code, TIMEOUT or REFUSED
     */
    private function runRounds(array $answers, int $retries): void
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $url) use (&$answers): Response {
                $this->requestedUrls[] = $url;
                $this->clock->advance(1_000_000);
                $answer = array_shift($answers[$url]);
                return match ($answer) {
                    self::TIMEOUT => throw new NetworkTimeoutException('cURL error 28', new Request('GET', $url)),
                    self::REFUSED => throw new ConnectException('cURL error 7', new Request('GET', $url)),
                    default => new Response('php://temp', is_int($answer) ? $answer : 404),
                };
            },
        );
        $pageFetcher = new PageFetcher($requestFactory);

        $subject = new class ($this->clock, function (int $microseconds): void {
            $this->pauses[] = $microseconds;
            $this->clock->advance($microseconds);
        }) extends RetryRounds {
            /**
             * @param \Closure(int): void $onPause
             */
            public function __construct(FakeClock $clock, private readonly \Closure $onPause)
            {
                parent::__construct($clock);
            }

            protected function pause(int $microseconds): void
            {
                ($this->onPause)($microseconds);
            }
        };

        $subject->run(
            array_keys($answers),
            $retries,
            static fn(string $url): FetchedPage => $pageFetcher->fetch($url, 5),
            static fn(FetchedPage $page): bool => $page->isRetryable(),
            function (string $url, FetchedPage $page): void {
                self::assertArrayNotHasKey($url, $this->results, 'A result is handed out once per item.');
                $this->results[$url] = $page;
            },
            function (int $round, int $count): void {
                $this->rounds[] = [$round, $count];
            },
        );
    }
}
