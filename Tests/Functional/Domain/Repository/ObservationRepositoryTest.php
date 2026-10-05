<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Functional\Domain\Repository;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ObservationRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['oliverthiele/ot-websitecheck'];

    private ObservationRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Observations.csv');
        $this->subject = new ObservationRepository($this->get(ConnectionPool::class));
    }

    #[Test]
    public function rerunRemovesOnlyTheRowsItDoesNotProduceAgain(): void
    {
        // The re-run covers /a/ only, and no further target pages.
        $removed = $this->subject->deleteRowsOfRunExcept('relaunch', [
            'live' => ['/a/' => true],
            'staging' => ['/a/' => true],
        ]);

        self::assertSame(3, $removed);
        $this->assertCSVDataSet(__DIR__ . '/Fixtures/ObservationsAfterRerun.csv');
    }

    #[Test]
    public function rerunWithAnotherTargetLabelRemovesTheOldTargetRows(): void
    {
        $removed = $this->subject->deleteRowsOfRunExcept('relaunch', [
            'live' => ['/a/' => true, '/b/' => true],
            'staging-new' => ['/a/' => true, '/b/' => true],
        ]);

        self::assertSame(3, $removed);
        self::assertSame([1, 3], array_map(static fn(Observation $observation): int => $observation->uid, $this->subject->findByRun('relaunch')));
    }

    #[Test]
    public function emptyRunLabelRemovesNothing(): void
    {
        self::assertSame(0, $this->subject->deleteRowsOfRunExcept('', []));
        self::assertCount(5, $this->subject->findByRun('relaunch'));
    }

    #[Test]
    public function storedRowReplacesTheRowOfTheSameEnvironmentAndPath(): void
    {
        $rows = $this->subject->findRowsByRun('earlier', Observation::ROLE_REFERENCE);
        self::assertCount(1, $rows);

        $this->subject->storeRow('relaunch', ['environment' => 'live', 'final_status' => 301] + $rows[0]);
        $this->subject->storeRow('relaunch', ['environment' => 'live-copy'] + $rows[0]);

        $referenceRows = $this->subject->findRowsByRun('relaunch', Observation::ROLE_REFERENCE);
        self::assertCount(3, $referenceRows);
        $byEnvironmentAndPath = [];
        foreach ($referenceRows as $row) {
            $byEnvironmentAndPath[$row['environment'] . ' ' . $row['requested_path']] = $row['final_status'];
        }
        self::assertSame(['live /a/' => 301, 'live /b/' => 200, 'live-copy /a/' => 200], $byEnvironmentAndPath);
    }

    #[Test]
    public function canonicalOfTheFinalPageIsStored(): void
    {
        $chain = new RedirectChain([
            ['url' => 'https://staging.example.com/old/', 'status' => 301],
            ['url' => 'https://staging.example.com/shortcut/', 'status' => 307, 'redirectBy' => 'TYPO3 Shortcut/Mountpoint'],
            ['url' => 'https://staging.example.com/alias/', 'status' => 200],
        ], '');
        $this->subject->storeObservation('relaunch', 'staging', Observation::ROLE_TARGET, 'pages', $chain, new PageIdentity(11, 'de-de'), 1790001000, 'https://staging.example.com/original/');

        $stored = array_values(array_filter(
            $this->subject->findByRun('relaunch'),
            static fn(Observation $observation): bool => $observation->requestedPath === '/old/',
        ));
        self::assertCount(1, $stored);
        self::assertSame('https://staging.example.com/original/', $stored[0]->canonicalUrl);
        self::assertSame('TYPO3 Shortcut/Mountpoint', $stored[0]->redirectChain[1]['redirectBy'] ?? '');
        self::assertArrayNotHasKey('redirectBy', $stored[0]->redirectChain[0]);
    }

    #[Test]
    public function referenceEnvironmentsAreListedPerRun(): void
    {
        self::assertSame(['earlier' => ['live-old'], 'relaunch' => ['live']], $this->sorted($this->subject->findReferenceEnvironmentsByRun()));
    }

    #[Test]
    public function mostRecentlyCheckedRunComesFirst(): void
    {
        self::assertSame(['relaunch', 'earlier'], $this->subject->findDistinctRuns());
    }

    #[Test]
    public function deletingARunLeavesOtherRuns(): void
    {
        self::assertSame(5, $this->subject->deleteRun('relaunch'));
        self::assertSame(['earlier'], $this->subject->findDistinctRuns());
    }

    /**
     * @param array<string, list<string>> $environments
     * @return array<string, list<string>>
     */
    private function sorted(array $environments): array
    {
        ksort($environments);

        return $environments;
    }
}
