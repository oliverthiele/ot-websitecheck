<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Fixtures;

use Psr\Clock\ClockInterface;

/**
 * A clock that only moves when a test moves it.
 */
final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new \DateTimeImmutable('2026-01-01 10:00:00.000000');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $microseconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d microseconds', $microseconds));
    }
}
