<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Service\MigrationCheckSuggestion;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MigrationCheckSuggestionTest extends UnitTestCase
{
    #[Test]
    public function lockedLiveIsComparedWithNewestStaging(): void
    {
        $suggestion = $this->suggest([
            $this->snapshot(1, 'www.example.com', 100, 'live', locked: true),
            $this->snapshot(2, 'www.example.com', 300, 'live'),
            $this->snapshot(3, 'staging.example.com', 200, 'staging'),
            $this->snapshot(4, 'staging.example.com', 250, 'staging'),
            $this->snapshot(5, 'example.ddev.site', 400, 'local'),
        ]);

        self::assertSame([1, 4], $suggestion);
    }

    #[Test]
    public function withoutStagingTheNextEnvironmentIsTheTarget(): void
    {
        self::assertSame([1, 3], $this->suggest([
            $this->snapshot(1, 'www.example.com', 100, 'live'),
            $this->snapshot(2, 'dev.example.com', 150, 'development'),
            $this->snapshot(3, 'dev.example.com', 200, 'development'),
            $this->snapshot(4, 'example.ddev.site', 300, 'local'),
        ]));
    }

    #[Test]
    public function newerLiveSnapshotIsTheTargetOnlyWithoutAnyOtherEnvironment(): void
    {
        self::assertSame([1, 3], $this->suggest([
            $this->snapshot(1, 'www.example.com', 100, 'live', locked: true),
            $this->snapshot(2, 'www.example.com', 50, 'live'),
            $this->snapshot(3, 'www.example.com', 300, 'live'),
        ]));
    }

    #[Test]
    public function withoutEnvironmentsTheOldestIsComparedWithTheNewestOtherHost(): void
    {
        self::assertSame([1, 3], $this->suggest([
            $this->snapshot(1, 'www.example.com', 100),
            $this->snapshot(2, 'www.example.com', 300),
            $this->snapshot(3, 'staging.example.com', 200),
        ]));
    }

    #[Test]
    public function incompleteSnapshotsAreNeverSuggested(): void
    {
        self::assertSame([2, 0], $this->suggest([
            $this->snapshot(1, 'www.example.com', 300, 'live', status: SitemapSnapshot::STATUS_IMPORTING),
            $this->snapshot(2, 'www.example.com', 100, 'live'),
            $this->snapshot(3, 'staging.example.com', 200, 'staging', status: SitemapSnapshot::STATUS_IMPORTING),
        ]));
    }

    #[Test]
    public function nothingIsSuggestedWithoutSnapshots(): void
    {
        self::assertSame([0, 0], $this->suggest([]));
    }

    /**
     * @param list<SitemapSnapshot> $snapshots
     * @return array{int, int} uids of reference and target, 0 for none
     */
    private function suggest(array $snapshots): array
    {
        $suggestion = (new MigrationCheckSuggestion())->suggest($snapshots);

        return [$suggestion['reference']->uid ?? 0, $suggestion['target']->uid ?? 0];
    }

    private function snapshot(int $uid, string $host, int $fetchedAt, string $environment = '', bool $locked = false, string $status = SitemapSnapshot::STATUS_COMPLETE): SitemapSnapshot
    {
        return new SitemapSnapshot($uid, 'snapshot ' . $uid, 'https://' . $host . '/', $fetchedAt, $status, locked: $locked, environment: $environment);
    }
}
