<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\FetchedPage;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;
use OliverThiele\OtWebsitecheck\Service\PageFetcher;
use OliverThiele\OtWebsitecheck\Service\SitemapDocumentCrawler;
use OliverThiele\OtWebsitecheck\Service\SitemapGroupExtractor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A snapshot is only worth something if it shows what was missing: a failed
 * sub-sitemap must end up as a document, not silently disappear.
 */
final class SitemapDocumentCrawlerTest extends UnitTestCase
{
    private const string INDEX = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<sitemap><loc>https://www.example.com/sitemap.xml?tx_seo%5Bsitemap%5D=pages</loc></sitemap>'
        . '<sitemap><loc>https://www.example.com/sitemap.xml?tx_seo%5Bsitemap%5D=products</loc></sitemap>'
        . '</sitemapindex>';

    private const string PAGES = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<url><loc>https://www.example.com/</loc><lastmod>2026-01-01T10:00:00+01:00</lastmod></url>'
        . '<url><loc>https://www.example.com/about/</loc></url>'
        . '</urlset>';

    #[Test]
    public function indexAndSubSitemapsAreReturnedWithGroupsAndEntries(): void
    {
        $documents = $this->crawl([
            'https://www.example.com/sitemap.xml' => new FetchedPage(200, self::INDEX),
            'https://www.example.com/sitemap.xml?tx_seo%5Bsitemap%5D=pages' => new FetchedPage(200, self::PAGES),
            'https://www.example.com/sitemap.xml?tx_seo%5Bsitemap%5D=products' => new FetchedPage(500, 'Oops'),
        ]);

        self::assertCount(3, $documents);
        self::assertSame(SitemapDocument::TYPE_INDEX, $documents[0]->type);

        self::assertSame(SitemapDocument::TYPE_URLSET, $documents[1]->type);
        self::assertSame('pages', $documents[1]->sitemapGroup);
        self::assertSame('https://www.example.com/sitemap.xml', $documents[1]->parentUrl);
        self::assertSame([
            ['url' => 'https://www.example.com/', 'lastmod' => '2026-01-01T10:00:00+01:00'],
            ['url' => 'https://www.example.com/about/', 'lastmod' => ''],
        ], $documents[1]->entries);

        self::assertSame(SitemapDocument::TYPE_INVALID, $documents[2]->type);
        self::assertSame('products', $documents[2]->sitemapGroup);
        self::assertSame(500, $documents[2]->httpStatus);
        self::assertFalse($documents[2]->isUsable());
    }

    #[Test]
    public function unreachableSitemapIsKeptAsDocument(): void
    {
        $documents = $this->crawl(['https://www.example.com/sitemap.xml' => new FetchedPage(0, '')]);

        self::assertCount(1, $documents);
        self::assertSame(SitemapDocument::TYPE_UNREACHABLE, $documents[0]->type);
    }

    #[Test]
    public function htmlErrorPageWithStatus200IsNotAValidSitemap(): void
    {
        $documents = $this->crawl(['https://www.example.com/sitemap.xml' => new FetchedPage(200, '<!DOCTYPE html><html><body>Not found</body></html>')]);

        self::assertSame(SitemapDocument::TYPE_INVALID, $documents[0]->type);
    }

    #[Test]
    public function indexListingItselfIsFetchedOnlyOnce(): void
    {
        $selfReferencing = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://www.example.com/sitemap.xml</loc></sitemap></sitemapindex>';

        $documents = $this->crawl(['https://www.example.com/sitemap.xml' => new FetchedPage(200, $selfReferencing)]);

        self::assertCount(1, $documents);
    }

    /**
     * @param array<string, FetchedPage> $pages
     * @return list<SitemapDocument>
     */
    private function crawl(array $pages): array
    {
        $pageFetcher = self::createStub(PageFetcher::class);
        $pageFetcher->method('fetch')->willReturnCallback(
            static fn(string $url): FetchedPage => $pages[$url] ?? new FetchedPage(404, ''),
        );

        return (new SitemapDocumentCrawler($pageFetcher, new SitemapGroupExtractor()))->crawl('https://www.example.com/sitemap.xml', 5);
    }
}
