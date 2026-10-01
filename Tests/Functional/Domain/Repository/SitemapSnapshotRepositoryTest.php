<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Functional\Domain\Repository;

use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SitemapSnapshotRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['oliverthiele/ot-websitecheck'];

    private SitemapSnapshotRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Snapshots.csv');
        $this->subject = new SitemapSnapshotRepository($this->get(ConnectionPool::class));
    }

    #[Test]
    public function rootDocumentUrlsAreTheSitemapsTheImportStartedFrom(): void
    {
        self::assertSame(
            ['https://www.example.com/sitemap.xml', 'https://www.example.org/en/sitemap.xml'],
            $this->subject->findRootDocumentUrls(1),
        );
    }

    #[Test]
    public function urlListedTwiceKeepsTheGroupItWasFirstListedWith(): void
    {
        self::assertSame(
            ['https://www.example.com/' => 'pages', 'https://www.example.com/about/' => 'pages'],
            $this->subject->findUrls(1),
        );
    }

    #[Test]
    public function deletingASnapshotRemovesItsSitemapsAndUrlsAndNothingElse(): void
    {
        self::assertTrue($this->subject->deleteSnapshot(1));

        $this->assertCSVDataSet(__DIR__ . '/Fixtures/SnapshotsAfterDelete.csv');
    }

    #[Test]
    public function lockedSnapshotIsNotDeleted(): void
    {
        self::assertFalse($this->subject->deleteSnapshot(2));
        self::assertNotNull($this->subject->findByUid(2));
        self::assertSame(1, $this->subject->countUrlsOfSnapshot(2));
    }

    #[Test]
    public function unknownSnapshotIsNotDeleted(): void
    {
        self::assertFalse($this->subject->deleteSnapshot(99));
    }

    #[Test]
    public function lockIsToggled(): void
    {
        self::assertTrue($this->subject->toggleLocked(1));
        self::assertTrue($this->subject->findByUid(1)?->locked);
        self::assertFalse($this->subject->toggleLocked(1));
        self::assertNull($this->subject->toggleLocked(99));
    }

    #[Test]
    public function missingUuidIsAssignedOnceAndKept(): void
    {
        $snapshot = $this->subject->findByUid(3);
        self::assertNotNull($snapshot);
        $uuid = $this->subject->ensureUuid($snapshot);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
        self::assertSame(3, $this->subject->findByUuid($uuid)?->uid);
        self::assertSame($uuid, $this->subject->ensureUuid($this->subject->findByUid(3) ?? $snapshot));
    }

    #[Test]
    public function labelsAreFoundExactly(): void
    {
        self::assertTrue($this->subject->labelExists('live'));
        self::assertFalse($this->subject->labelExists('LIVE '));
        self::assertSame(3, $this->subject->findByLabel('staging')?->uid);
    }
}
