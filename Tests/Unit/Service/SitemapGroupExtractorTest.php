<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use OliverThiele\OtWebsitecheck\Service\SitemapGroupExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Set\SetDefinition;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Snapshots of a TYPO3 v13 and a v14 site are only comparable when both give
 * the same group names — whether the group sits in the query or in the path.
 */
final class SitemapGroupExtractorTest extends UnitTestCase
{
    /**
     * The route enhancer the EXT:seo site set "typo3/seo-sitemap" ships.
     */
    private const array SEO_SITEMAP_ROUTE = [
        'type' => 'Simple',
        'routePath' => 'sitemap-type/{sitemap}',
        'aspects' => ['sitemap' => ['type' => 'StaticValueMapper', 'map' => ['pages' => 'pages']]],
        '_arguments' => ['sitemap' => 'tx_seo/sitemap'],
    ];

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
        self::assertSame($expectedGroup, $this->extractorFor([])->extract($sitemapUrl));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function routedSitemapUrls(): iterable
    {
        yield 'default language' => ['https://www.example.com/sitemap-type/pages/sitemap.xml', 'pages'];
        yield 'language prefix' => ['https://www.example.com/de/sitemap-type/pages/sitemap.xml', 'pages'];
        yield 'value not in the map' => ['https://www.example.com/sitemap-type/news/sitemap.xml', ''];
        yield 'route segment only as part of a word' => ['https://www.example.com/my-sitemap-type/pages/sitemap.xml', ''];
        yield 'query wins over path' => ['https://www.example.com/sitemap-type/pages/sitemap.xml?tx_seo%5Bsitemap%5D=products', 'products'];
    }

    #[Test]
    #[DataProvider('routedSitemapUrls')]
    public function groupIsReadFromTheRouteOfTheSite(string $sitemapUrl, string $expectedGroup): void
    {
        $subject = $this->extractorFor(['base' => 'https://www.example.com/', 'routeEnhancers' => ['Sitemap' => self::SEO_SITEMAP_ROUTE]]);

        self::assertSame($expectedGroup, $subject->extract($sitemapUrl));
    }

    #[Test]
    public function siteWithoutTheRouteDoesNotReadThePath(): void
    {
        $subject = $this->extractorFor(['base' => 'https://www.example.com/']);

        self::assertSame('', $subject->extract('https://www.example.com/sitemap-type/pages/sitemap.xml'));
    }

    #[Test]
    public function foreignHostGetsTheRouteOfTheSeoSitemapSet(): void
    {
        $subject = $this->extractorFor(['base' => 'https://www.example.com/']);

        self::assertSame('pages', $subject->extract('https://www.example.org/en/sitemap-type/pages/sitemap.xml'));
    }

    #[Test]
    public function routeValueIsMappedToTheGroup(): void
    {
        $route = self::SEO_SITEMAP_ROUTE;
        $route['routePath'] = '/sitemaps/{type}';
        $route['aspects'] = ['type' => [
            'type' => 'StaticValueMapper',
            'map' => ['pages' => 'pages'],
            'localeMap' => [['locale' => 'de_.*', 'map' => ['seiten' => 'pages']]],
        ]];
        $route['_arguments'] = ['type' => 'tx_seo/sitemap'];
        $subject = $this->extractorFor(['base' => 'https://www.example.com/', 'routeEnhancers' => ['Sitemap' => $route]]);

        self::assertSame('pages', $subject->extract('https://www.example.com/de/sitemaps/seiten/sitemap.xml'));
    }

    #[Test]
    public function routeWithoutMapperUsesTheValueAsGroup(): void
    {
        $route = self::SEO_SITEMAP_ROUTE;
        unset($route['aspects']);
        $route['_arguments'] = ['sitemap' => 'sitemap'];
        $subject = $this->extractorFor(['base' => 'https://www.example.com/', 'routeEnhancers' => ['Sitemap' => $route]]);

        self::assertSame('products', $subject->extract('https://www.example.com/sitemap-type/products/sitemap.xml'));
    }

    #[Test]
    public function routeWithoutStaticPartIsIgnored(): void
    {
        $route = self::SEO_SITEMAP_ROUTE;
        $route['routePath'] = '/{sitemap}';
        unset($route['aspects']);
        $subject = $this->extractorFor(['base' => 'https://www.example.com/', 'routeEnhancers' => ['Sitemap' => $route]]);

        self::assertSame('', $subject->extract('https://www.example.com/de/sitemap.xml'));
    }

    /**
     * @param array<string, mixed> $configuration Site configuration; empty for no site at all.
     */
    private function extractorFor(array $configuration): SitemapGroupExtractor
    {
        $siteFinder = self::createStub(SiteFinder::class);
        if ($configuration !== []) {
            $site = self::createStub(Site::class);
            $site->method('getIdentifier')->willReturn('main');
            $site->method('getConfiguration')->willReturn($configuration);
            $siteFinder->method('getAllSites')->willReturn(['main' => $site]);
        }
        $setRegistry = self::createStub(SetRegistry::class);
        $setRegistry->method('getSet')->willReturnCallback(
            static fn(string $setName): ?SetDefinition => $setName === SiteBaseProvider::SEO_SITEMAP_SET
                ? new SetDefinition(name: $setName, label: 'Sitemap', routeEnhancers: ['Sitemap' => self::SEO_SITEMAP_ROUTE])
                : null,
        );

        return new SitemapGroupExtractor(new SiteBaseProvider($siteFinder, $setRegistry));
    }
}
