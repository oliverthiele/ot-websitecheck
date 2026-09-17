<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\Repository\ObservationRepository;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use OliverThiele\OtWebsitecheck\Service\SnapshotArchive;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * An archive is the only copy once the database it came from is gone — these
 * tests pin down that nothing is lost on the way and that a damaged file is
 * refused instead of half imported.
 */
final class SnapshotArchiveTest extends UnitTestCase
{
    private const string SNAPSHOT_UUID = '01920000-0000-7000-8000-000000000001';
    private const string RUN_UUID = '01920000-0000-7000-8000-000000000002';

    #[Test]
    public function archiveSurvivesEncodingUnchanged(): void
    {
        $archive = $this->archive();
        $snapshotArchive = new SnapshotArchive();

        self::assertSame($archive, $snapshotArchive->decode($snapshotArchive->encode($archive)));
    }

    #[Test]
    public function fileOfAnotherFormatIsRefused(): void
    {
        $this->expectException(SnapshotArchiveException::class);
        $this->expectExceptionCode(1789490004);

        (new SnapshotArchive())->decode((string)gzencode('{"format":"something-else","version":1}'));
    }

    #[Test]
    public function unknownFormatVersionIsRefused(): void
    {
        $this->expectException(SnapshotArchiveException::class);
        $this->expectExceptionCode(1789490005);

        (new SnapshotArchive())->decode((string)gzencode('{"format":"ot-websitecheck-archive","version":2}'));
    }

    #[Test]
    public function uncompressedFileIsRefused(): void
    {
        $this->expectException(SnapshotArchiveException::class);
        $this->expectExceptionCode(1789490002);

        (new SnapshotArchive())->decode('{"format":"ot-websitecheck-archive","version":1}');
    }

    #[Test]
    public function snapshotWithoutUuidIsRefused(): void
    {
        $archive = $this->archive();
        $archive['snapshots'][0]['uuid'] = '';

        $this->expectException(SnapshotArchiveException::class);
        $this->expectExceptionCode(1789490006);

        $snapshotArchive = new SnapshotArchive();
        $snapshotArchive->decode($snapshotArchive->encode($archive));
    }

    #[Test]
    public function observationWithMissingFieldIsRefused(): void
    {
        $archive = $this->archive();
        unset($archive['runs'][0]['observations'][0]['final_status']);

        $this->expectException(SnapshotArchiveException::class);
        $this->expectExceptionMessage('"final_status" is not an integer in run "relaunch"');

        $snapshotArchive = new SnapshotArchive();
        $snapshotArchive->decode($snapshotArchive->encode($archive));
    }

    /**
     * @return array{createdAt: int, snapshots: list<array{uuid: string, label: string, startUrl: string, note: string, locked: bool, fetchedAt: int, documents: list<array{language: string, url: string, parentUrl: string, sitemapGroup: string, type: string, httpStatus: int, body: string, urls: list<array{url: string, lastmod: string}>}>}>, runs: list<array{uuid: string, label: string, referenceSnapshot: string, targetSnapshot: string, targetHost: string, startedAt: int, observations: list<array<string, int|string>>}>}
     */
    private function archive(): array
    {
        $observation = [];
        foreach (array_keys(ObservationRepository::ROW_FIELDS) as $field) {
            $observation[$field] = '';
        }
        $observation = array_merge($observation, [
            'environment' => 'live',
            'role' => 'reference',
            'requested_url' => 'https://www.example.com/über-uns/',
            'requested_path' => '/über-uns/',
            'first_status' => 200,
            'final_status' => 200,
            'hop_count' => 0,
            'redirect_chain' => '[]',
            'page_uid' => 12,
            'record_uid' => 0,
            'checked_at' => 1_800_000_000,
            'reviewed' => 1,
            'note' => 'checked by hand',
        ]);

        return [
            'createdAt' => 1_800_000_100,
            'snapshots' => [[
                'uuid' => self::SNAPSHOT_UUID,
                'label' => 'live-before-relaunch',
                'startUrl' => 'https://www.example.com/',
                'note' => "Line one\nLine two",
                'locked' => true,
                'fetchedAt' => 1_800_000_000,
                'documents' => [[
                    'language' => 'de-DE',
                    'url' => 'https://www.example.com/sitemap.xml?tx_seo%5Bsitemap%5D=pages',
                    'parentUrl' => 'https://www.example.com/sitemap.xml',
                    'sitemapGroup' => 'pages',
                    'type' => 'urlset',
                    'httpStatus' => 200,
                    'body' => '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://www.example.com/über-uns/</loc></url></urlset>',
                    'urls' => [['url' => 'https://www.example.com/über-uns/', 'lastmod' => '2026-01-31T14:05:00+01:00']],
                ]],
            ]],
            'runs' => [[
                'uuid' => self::RUN_UUID,
                'label' => 'relaunch',
                'referenceSnapshot' => self::SNAPSHOT_UUID,
                'targetSnapshot' => '',
                'targetHost' => 'staging.example.com',
                'startedAt' => 1_800_000_050,
                'observations' => [$observation],
            ]],
        ];
    }
}
