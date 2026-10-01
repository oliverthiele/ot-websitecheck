<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Functional\Domain\Repository;

use OliverThiele\OtWebsitecheck\Domain\Repository\CheckResultRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CheckResultRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['oliverthiele/ot-websitecheck'];

    private CheckResultRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Checks.csv');
        $this->subject = new CheckResultRepository($this->get(ConnectionPool::class), GeneralUtility::makeInstance(Registry::class));
    }

    #[Test]
    public function reviewIsKeptWhileTheStatusStaysTheSame(): void
    {
        $this->subject->storeResult('https://www.example.com/', 'staging', 'staging-current', 1, 200, '', 1790001000, 1790000900);

        $row = $this->findRow('https://www.example.com/', 'staging');
        self::assertSame(1, (int)$row['reviewed']);
        self::assertSame('fine', $row['note']);
        self::assertSame(1790000900, (int)$row['run_started_at']);
    }

    #[Test]
    public function changedStatusNeedsReviewAgain(): void
    {
        $this->subject->storeResult('https://www.example.com/broken/', 'staging', 'staging-current', 2, 200, '', 1790001000, 1790000900);

        $row = $this->findRow('https://www.example.com/broken/', 'staging');
        self::assertSame(0, (int)$row['reviewed']);
        self::assertSame('known', $row['note']);
    }

    #[Test]
    public function newUrlIsInsertedPerEnvironment(): void
    {
        $this->subject->storeResult('https://www.example.com/broken/', 'live', 'live-current', 2, 500, '', 1790001000);

        self::assertSame(500, (int)$this->findRow('https://www.example.com/broken/', 'live')['http_status']);
        self::assertSame(500, (int)$this->findRow('https://www.example.com/broken/', 'staging')['http_status']);
    }

    #[Test]
    public function problemsAreRowsWithoutStatus200OrWithAMarker(): void
    {
        self::assertSame(6, $this->subject->countAll());
        self::assertSame(4, $this->subject->countAll('staging', true));
        self::assertSame(3, $this->subject->countAll('staging', true, true));
    }

    #[Test]
    public function resultsArePagedInTheOrderOfTheModule(): void
    {
        $firstPage = $this->subject->findAll('staging', false, false, 2, 0);
        $secondPage = $this->subject->findAll('staging', false, false, 2, 2);
        $all = $this->subject->findAll('staging');

        self::assertCount(5, $all);
        self::assertSame(array_column($all, 'uid'), [...array_column($firstPage, 'uid'), ...array_column($secondPage, 'uid'), ...array_column($this->subject->findAll('staging', false, false, 2, 4), 'uid')]);
        self::assertCount(2, $firstPage);
    }

    #[Test]
    public function latestRunStartComesFromTheResultsWithoutARegisteredRun(): void
    {
        self::assertSame(1790000000, $this->subject->findLatestRunStart('staging', 'staging-current'));
        self::assertSame(0, $this->subject->findLatestRunStart('staging', 'other-snapshot'));
    }

    #[Test]
    public function registeredRunWinsEvenWithoutAStoredResult(): void
    {
        // A run aborted before its first result: only the registry knows it.
        $this->subject->registerRunStart('staging', 'staging-current', 1790002000);

        self::assertSame(1790002000, $this->subject->findLatestRunStart('staging', 'staging-current'));
        self::assertSame([], $this->subject->findUrlsOfRun('staging', 'staging-current', 1790002000));
        self::assertSame(1790000500, $this->subject->findLatestRunStart('live', 'live-current'));
    }

    #[Test]
    public function urlsOfARunAreThoseStoredWithItsStart(): void
    {
        self::assertSame(
            [
                'https://www.example.com/' => true,
                'https://www.example.com/broken/' => true,
                'https://www.example.com/slow/' => true,
                'https://www.example.com/moved/' => true,
            ],
            $this->subject->findUrlsOfRun('staging', 'staging-current', 1790000000),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function findRow(string $url, string $environment): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable(CheckResultRepository::TABLE);
        $row = $queryBuilder->select('*')
            ->from(CheckResultRepository::TABLE)
            ->where(
                $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($url)),
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }
}
