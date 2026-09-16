<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Service\SnapshotRetentionPolicy;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A cleanup deletes irreversibly — these tests pin down what it must never take.
 */
final class SnapshotRetentionPolicyTest extends UnitTestCase
{
    private const int NOW = 1_800_000_000;

    #[Test]
    public function newestSnapshotsPerStartUrlAreKept(): void
    {
        $snapshots = [
            $this->snapshot(1, 'https://www.example.com/', self::NOW - 300),
            $this->snapshot(2, 'https://www.example.com/', self::NOW - 200),
            $this->snapshot(3, 'https://www.example.com/', self::NOW - 100),
            $this->snapshot(4, 'https://staging.example.com/', self::NOW - 400),
        ];

        self::assertSame([1], $this->selectedUids($snapshots, [], 2));
    }

    #[Test]
    public function snapshotsUsedByRunsOrWithNoteAreKept(): void
    {
        $snapshots = [
            $this->snapshot(1, 'https://www.example.com/', self::NOW - 400),
            $this->snapshot(2, 'https://www.example.com/', self::NOW - 300, note: 'before the relaunch'),
            $this->snapshot(3, 'https://www.example.com/', self::NOW - 200),
            $this->snapshot(4, 'https://www.example.com/', self::NOW - 100),
        ];

        self::assertSame([3], $this->selectedUids($snapshots, [1], 1));
    }

    #[Test]
    public function onlyOldIncompleteImportsAreRemoved(): void
    {
        $snapshots = [
            $this->snapshot(1, 'https://www.example.com/', self::NOW - 90_000, SitemapSnapshot::STATUS_IMPORTING),
            $this->snapshot(2, 'https://www.example.com/', self::NOW - 60, SitemapSnapshot::STATUS_IMPORTING),
        ];

        self::assertSame([1], $this->selectedUids($snapshots, [], 10));
    }

    #[Test]
    public function keepBelowOneStillKeepsTheNewestSnapshot(): void
    {
        $snapshots = [
            $this->snapshot(1, 'https://www.example.com/', self::NOW - 200),
            $this->snapshot(2, 'https://www.example.com/', self::NOW - 100),
        ];

        self::assertSame([1], $this->selectedUids($snapshots, [], 0));
    }

    /**
     * @param list<SitemapSnapshot> $snapshots
     * @param list<int> $protectedUids
     * @return list<int>
     */
    private function selectedUids(array $snapshots, array $protectedUids, int $keep): array
    {
        $selected = (new SnapshotRetentionPolicy())->selectForDeletion($snapshots, $protectedUids, $keep, self::NOW - 86_400);

        return array_map(static fn(SitemapSnapshot $snapshot): int => $snapshot->uid, $selected);
    }

    private function snapshot(int $uid, string $startUrl, int $fetchedAt, string $status = SitemapSnapshot::STATUS_COMPLETE, string $note = ''): SitemapSnapshot
    {
        return new SitemapSnapshot($uid, 'snapshot ' . $uid, $startUrl, $fetchedAt, $status, $note);
    }
}
