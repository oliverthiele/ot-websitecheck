<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use Psr\Clock\ClockInterface;

/**
 * Runs one attempt per item, then gives the items whose attempt failed in a
 * retryable way another round — after all other items, not right away. A page
 * that timed out while rendering for the first time after a cache flush has
 * usually finished by the time its turn comes again, and the work on the other
 * items is the pause.
 *
 * Only the last result of an item is handed out. A transient failure must not
 * be stored on its way to a success: storing a changed status resets the
 * review state of a result.
 */
class RetryRounds
{
    /**
     * Minimum time between two attempts on the same item. Only waited for when
     * a round is shorter than that, e.g. when the last item of a list failed.
     */
    private const int MINIMUM_GAP_MICROSECONDS = 5_000_000;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @template TItem
     * @template TResult
     * @param array<array-key, TItem> $items
     * @param int $retries number of rounds after the first one
     * @param \Closure(TItem): TResult $attempt
     * @param \Closure(TResult): bool $isRetryable
     * @param \Closure(TItem, TResult): void $onResult called exactly once per item, with its last result
     * @param (\Closure(int, int): void)|null $onRoundStart called with the round number (1 for the first)
     *                                                     and the number of items in it
     */
    public function run(
        array $items,
        int $retries,
        \Closure $attempt,
        \Closure $isRetryable,
        \Closure $onResult,
        ?\Closure $onRoundStart = null,
    ): void {
        $pending = $items;
        /** @var array<array-key, int> $lastAttemptAt */
        $lastAttemptAt = [];

        // The first round always starts, even without items, so a caller's
        // progress output is started and can be finished.
        for ($round = 1; $round === 1 || $pending !== []; $round++) {
            if ($onRoundStart !== null) {
                $onRoundStart($round, count($pending));
            }
            $isLastRound = $round > $retries;
            $retryLater = [];

            foreach ($pending as $key => $item) {
                if (isset($lastAttemptAt[$key])) {
                    $this->waitUntil($lastAttemptAt[$key] + self::MINIMUM_GAP_MICROSECONDS);
                }
                $result = $attempt($item);
                $lastAttemptAt[$key] = $this->nowInMicroseconds();

                if (!$isLastRound && $isRetryable($result)) {
                    $retryLater[$key] = $item;
                    continue;
                }
                $onResult($item, $result);
            }

            $pending = $retryLater;
        }
    }

    protected function pause(int $microseconds): void
    {
        usleep($microseconds);
    }

    private function waitUntil(int $microsecondTimestamp): void
    {
        $remaining = $microsecondTimestamp - $this->nowInMicroseconds();
        if ($remaining > 0) {
            $this->pause($remaining);
        }
    }

    private function nowInMicroseconds(): int
    {
        $now = $this->clock->now();

        return $now->getTimestamp() * 1_000_000 + (int)$now->format('u');
    }
}
