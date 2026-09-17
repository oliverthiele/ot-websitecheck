<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Service\SnapshotOverviewBuilder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SnapshotOverviewBuilderTest extends UnitTestCase
{
    private int $nextUid = 1;

    #[Test]
    public function indexIsNoGroupAndDoesNotCountAsSitemap(): void
    {
        $overview = (new SnapshotOverviewBuilder())->build([
            $this->document('de-DE', 'https://www.example.com/sitemap.xml', '', '', SitemapDocument::TYPE_INDEX),
            $this->document('de-DE', 'https://www.example.com/sitemap-pages.xml', 'https://www.example.com/sitemap.xml', 'pages', urlCount: 563),
            $this->document('de-DE', 'https://www.example.com/sitemap-news.xml', 'https://www.example.com/sitemap.xml', 'news', urlCount: 40),
        ]);

        self::assertSame(['news', 'pages'], $overview['groupNames']);
        self::assertSame(1, $overview['indexCount']);
        self::assertSame(2, $overview['sitemapCount']);
        self::assertSame(603, $overview['urlCount']);
        $languageRow = $overview['languages'][0];
        self::assertCount(1, $languageRow['indexFiles']);
        self::assertSame([['name' => 'news', 'urlCount' => 40, 'missing' => false], ['name' => 'pages', 'urlCount' => 563, 'missing' => false]], $languageRow['cells']);
    }

    #[Test]
    public function paginatedGroupSumsItsSitemaps(): void
    {
        $overview = (new SnapshotOverviewBuilder())->build([
            $this->document('en-US', 'https://www.example.com/sitemap.xml?page=1', '', 'pages', urlCount: 1000),
            $this->document('en-US', 'https://www.example.com/sitemap.xml?page=2', '', 'pages', urlCount: 12),
        ]);

        $group = $overview['languages'][0]['groups'][0];
        self::assertSame(2, $group['sitemapCount']);
        self::assertSame(1012, $group['urlCount']);
        self::assertSame(1012, $overview['languages'][0]['cells'][0]['urlCount']);
    }

    #[Test]
    public function groupOfOtherLanguagesIsMissingWhenAbsentOrEmpty(): void
    {
        $overview = (new SnapshotOverviewBuilder())->build([
            $this->document('de-DE', 'https://www.example.com/de/pages.xml', 'https://www.example.com/de/sitemap.xml', 'pages', urlCount: 10),
            $this->document('de-DE', 'https://www.example.com/de/news.xml', 'https://www.example.com/de/sitemap.xml', 'news', urlCount: 5),
            $this->document('fr-FR', 'https://www.example.com/fr/pages.xml', 'https://www.example.com/fr/sitemap.xml', 'pages', urlCount: 9),
            $this->document('fr-FR', 'https://www.example.com/fr/news.xml', 'https://www.example.com/fr/sitemap.xml', 'news', urlCount: 0),
            $this->document('it-IT', 'https://www.example.com/it/pages.xml', 'https://www.example.com/it/sitemap.xml', 'pages', urlCount: 8),
        ]);

        self::assertSame(['de-DE', 'fr-FR', 'it-IT'], array_column($overview['languages'], 'language'));
        self::assertSame([0, 1, 1], array_column($overview['languages'], 'missingCount'));
        self::assertTrue($overview['languages'][2]['cells'][0]['missing']);
        self::assertCount(1, $overview['languages'][2]['groups']);
    }

    #[Test]
    public function unreadableRootSitemapTakesThePlaceOfTheIndex(): void
    {
        $overview = (new SnapshotOverviewBuilder())->build([
            $this->document('de-DE', 'https://www.example.com/de/pages.xml', 'https://www.example.com/de/sitemap.xml', 'pages', urlCount: 10),
            $this->document('fr-FR', 'https://www.example.com/fr/sitemap.xml', '', '', SitemapDocument::TYPE_UNREACHABLE),
        ]);

        self::assertSame(['pages'], $overview['groupNames']);
        $languageRow = $overview['languages'][1];
        self::assertTrue($languageRow['indexFailed']);
        self::assertSame(1, $languageRow['failedCount']);
        self::assertSame(1, $languageRow['missingCount']);
        self::assertSame(0, $languageRow['sitemapCount']);
        self::assertSame(1, $overview['failedCount']);
    }

    /**
     * @return array{uid: int, language: string, url: string, parentUrl: string, sitemapGroup: string, type: string, httpStatus: int, urlCount: int}
     */
    private function document(string $language, string $url, string $parentUrl, string $sitemapGroup, string $type = SitemapDocument::TYPE_URLSET, int $urlCount = 0): array
    {
        return [
            'uid' => $this->nextUid++,
            'language' => $language,
            'url' => $url,
            'parentUrl' => $parentUrl,
            'sitemapGroup' => $sitemapGroup,
            'type' => $type,
            'httpStatus' => SitemapDocument::isUsableType($type) ? 200 : 0,
            'urlCount' => $urlCount,
        ];
    }
}
