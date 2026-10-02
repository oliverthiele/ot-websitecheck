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

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<int>
     */
    private function uidsOf(array $rows): array
    {
        $uids = array_map(static fn(array $row): int => is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0, $rows);
        sort($uids);

        return $uids;
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
    public function finalAndCanonicalUrlAreStored(): void
    {
        $this->subject->storeResult('https://www.example.com/alias/', 'staging', 'staging-current', 7, 200, 'canonicalElsewhere', 1790001000, 1790000900, '', 'https://www.example.com/original/');
        $this->subject->storeResult('https://www.example.com/moved/', 'staging', 'staging-current', 4, 200, 'redirectChain', 1790001000, 1790000900, 'https://www.example.com/new/');

        $alias = $this->findRow('https://www.example.com/alias/', 'staging');
        self::assertSame('', $alias['final_url']);
        self::assertSame('https://www.example.com/original/', $alias['canonical_url']);
        $moved = $this->findRow('https://www.example.com/moved/', 'staging');
        self::assertSame('https://www.example.com/new/', $moved['final_url']);
        self::assertSame('', $moved['canonical_url']);
    }

    #[Test]
    public function metadataFindingsAreStoredAndFiltered(): void
    {
        $this->subject->storeResult('https://www.example.com/news/a', 'staging', 'staging-current', 7, 200, '', 1790001000, 1790000900, '', '', 0, '{"title":"News"}');
        $pages = $this->subject->findPagesWithMetadata('staging');
        self::assertCount(1, $pages);
        self::assertSame('News', $pages[0]['metadata']->title);

        $this->subject->updateMetaFindings($pages[0]['uid'], ['metaShared', 'metaDescriptionMissing']);

        self::assertSame(1, $this->subject->countAll('staging', false, false, [], null, 'metaDescriptionMissing'));
        self::assertSame(1, $this->subject->countAll('staging', false, false, [], null, 'metaShared'));
        self::assertSame(0, $this->subject->countAll('staging', false, false, [], null, 'listedButNoindex'));
    }

    #[Test]
    public function resultsAreFilteredByMarker(): void
    {
        self::assertSame(['redirected', 'timeout'], $this->subject->findDistinctMarkers('staging'));
        self::assertSame([], $this->subject->findDistinctMarkers('live'));
        self::assertSame(1, $this->subject->countAll('staging', false, false, ['redirected']));
        self::assertSame(2, $this->subject->countAll('', true, false, ['redirected', 'timeout']));
        self::assertSame([4], array_map(static fn(array $row): int => (int)$row['uid'], $this->subject->findAll('staging', false, false, 0, 0, ['redirected'])));
    }

    #[Test]
    public function resultsAreFilteredByWhoActs(): void
    {
        // Fixture: 200 (1, 5), 500 (2), timeout (3), redirected (4), 404 (6).
        $integrator = ['markers' => ['timeout', 'redirected'], 'plain' => CheckResultRepository::PLAIN_OTHER_ERROR];
        $editor = ['markers' => [], 'plain' => CheckResultRepository::PLAIN_NOT_FOUND];
        $nobody = ['markers' => [], 'plain' => CheckResultRepository::PLAIN_OK];

        self::assertSame([2, 3, 4], $this->uidsOf($this->subject->findAll('staging', false, false, 0, 0, [], $integrator)));
        self::assertSame([6], $this->uidsOf($this->subject->findAll('staging', false, false, 0, 0, [], $editor)));
        self::assertSame([1], $this->uidsOf($this->subject->findAll('staging', false, false, 0, 0, [], $nobody)));
        self::assertSame(0, $this->subject->countAll('', false, false, [], ['markers' => [], 'plain' => '']));
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
