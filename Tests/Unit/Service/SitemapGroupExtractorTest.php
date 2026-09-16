<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\SitemapGroupExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Snapshots of a TYPO3 v13 and a v14 site are only comparable when both give
 * the same group names.
 */
final class SitemapGroupExtractorTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function sitemapUrls(): iterable
    {
        yield 'TYPO3 v13' => ['https://www.example.com/sitemap.xml?sitemap=pages&cHash=abc', 'pages'];
        yield 'TYPO3 v14' => ['https://www.example.com/de/sitemap.xml?tx_seo%5Bsitemap%5D=products&cHash=abc', 'products'];
        yield 'TYPO3 v14, paginated' => ['https://www.example.com/sitemap.xml?tx_seo%5Bpage%5D=2&tx_seo%5Bsitemap%5D=pages&cHash=abc', 'pages'];
        yield 'sitemap index' => ['https://www.example.com/sitemap.xml', ''];
        yield 'foreign query' => ['https://www.example.com/sitemap.xml?lang=de', ''];
    }

    #[Test]
    #[DataProvider('sitemapUrls')]
    public function groupIsReadFromEitherQueryArgument(string $sitemapUrl, string $expectedGroup): void
    {
        self::assertSame($expectedGroup, (new SitemapGroupExtractor())->extract($sitemapUrl));
    }
}
