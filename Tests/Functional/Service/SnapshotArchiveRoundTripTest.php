<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Functional\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\Repository\MigrationRunRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchive;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchiveExporter;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchiveImporter;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The archive is what carries the reference results of a relaunch across a
 * replaced database: it has to restore everything, and importing it again
 * must add nothing.
 */
final class SnapshotArchiveRoundTripTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['oliverthiele/ot-websitecheck'];

    private SitemapSnapshotRepository $snapshotRepository;
    private MigrationRunRepository $runRepository;
    private ObservationRepository $observationRepository;
    private SnapshotArchiveExporter $exporter;
    private SnapshotArchiveImporter $importer;
    private SnapshotArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Domain/Repository/Fixtures/Snapshots.csv');
        $this->importCSVDataSet(__DIR__ . '/../Domain/Repository/Fixtures/Observations.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/MigrationRuns.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        $this->snapshotRepository = new SitemapSnapshotRepository($connectionPool);
        $this->runRepository = new MigrationRunRepository($connectionPool);
        $this->observationRepository = new ObservationRepository($connectionPool);
        $this->exporter = new SnapshotArchiveExporter($this->snapshotRepository, $this->runRepository, $this->observationRepository);
        $this->importer = new SnapshotArchiveImporter($this->snapshotRepository, $this->runRepository, $this->observationRepository, $connectionPool);
        $this->archive = new SnapshotArchive();
    }

    #[Test]
    public function runAndItsSnapshotsSurviveAReplacedDatabase(): void
    {
        $content = $this->archive->encode($this->exporter->export([], false, ['relaunch'])['archive']);

        // The database is replaced, e.g. by a fresh import of the live database.
        $this->observationRepository->deleteRun('relaunch');
        $this->runRepository->deleteRun('relaunch');
        self::assertTrue($this->snapshotRepository->deleteSnapshot(1));
        self::assertTrue($this->snapshotRepository->deleteSnapshot(3));

        $decoded = $this->archive->decode($content);
        $this->importer->import($decoded, $this->importer->plan($decoded));

        $live = $this->snapshotRepository->findByLabel('live');
        $staging = $this->snapshotRepository->findByLabel('staging');
        self::assertNotNull($live);
        self::assertNotNull($staging);
        self::assertSame('live', $live->environment);
        self::assertSame(3, $this->snapshotRepository->countUrlsOfSnapshot($live->uid));
        self::assertSame(['https://www.example.com/sitemap.xml', 'https://www.example.org/en/sitemap.xml'], $this->snapshotRepository->findRootDocumentUrls($live->uid));

        $run = $this->runRepository->findByLabel('relaunch');
        self::assertNotNull($run);
        self::assertSame($live->uid, $run['referenceSnapshotUid']);
        self::assertSame($staging->uid, $run['targetSnapshotUid']);

        $observations = $this->observationRepository->findByRun('relaunch');
        self::assertCount(5, $observations);
        $reviewedTargets = array_filter($observations, static fn(Observation $observation): bool => $observation->role === Observation::ROLE_TARGET && $observation->reviewed);
        self::assertCount(1, $reviewedTargets);
    }

    #[Test]
    public function importingTheSameArchiveAgainAddsNothing(): void
    {
        $decoded = $this->archive->decode($this->archive->encode($this->exporter->export(['live'], true, ['relaunch'])['archive']));

        $plan = $this->importer->plan($decoded);
        $this->importer->import($decoded, $plan);

        self::assertSame(['skip'], array_values(array_unique(array_column([...$plan['snapshots'], ...$plan['runs']], 'action'))));
        self::assertCount(3, $this->snapshotRepository->findAll());
        self::assertCount(5, $this->observationRepository->findByRun('relaunch'));
    }

    #[Test]
    public function labelTakenByAnotherSnapshotStopsTheImportUnlessASuffixIsGiven(): void
    {
        $decoded = $this->archive->decode($this->archive->encode($this->exporter->export(['staging'], false, [])['archive']));
        self::assertTrue($this->snapshotRepository->deleteSnapshot(3));
        $this->snapshotRepository->createSnapshot('staging', 'https://staging.example.com/', '', 1790009000);

        try {
            $this->importer->plan($decoded);
            self::fail('A label conflict must stop the import.');
        } catch (SnapshotArchiveException) {
        }

        $this->importer->import($decoded, $this->importer->plan($decoded, '-restored'));
        self::assertNotNull($this->snapshotRepository->findByLabel('staging-restored'));
        // live, live locked, the new staging and the restored one
        self::assertCount(4, $this->snapshotRepository->findAll());
    }
}
