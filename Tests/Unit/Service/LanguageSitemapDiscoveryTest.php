<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Service\LanguageSitemapDiscovery;
use OliverThiele\OtWebsitecheck\Service\RedirectChainFollower;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LanguageSitemapDiscoveryTest extends UnitTestCase
{
    #[Test]
    public function everyHreflangLinkGivesOneSitemapAndXDefaultIsSkipped(): void
    {
        $html = '<html><head>'
            . '<link rel="alternate" hreflang="en-US" href="https://www.example.com/">'
            . '<link hreflang="de-DE" rel="alternate" href="/de/">'
            . '<link rel="alternate" hreflang="x-default" href="https://www.example.com/">'
            . '<link rel="stylesheet" href="/style.css">'
            . '</head><body></body></html>';

        $sitemaps = $this->discoveryAnswering(200, $html)->discover('https://www.example.com/', 'sitemap.xml', 5);

        self::assertSame([
            'en-US' => 'https://www.example.com/sitemap.xml',
            'de-DE' => 'https://www.example.com/de/sitemap.xml',
        ], $sitemaps);
    }

    #[Test]
    public function startPageWithoutWorkingAnswerGivesNoLanguages(): void
    {
        $sitemaps = $this->discoveryAnswering(500, '<link rel="alternate" hreflang="en" href="/">')->discover('https://www.example.com/', 'sitemap.xml', 5);

        self::assertSame([], $sitemaps);
    }

    #[Test]
    public function pageTypeArgumentIsUsedAsQueryString(): void
    {
        $subject = new LanguageSitemapDiscovery(self::createStub(RedirectChainFollower::class));

        self::assertSame('https://www.example.com/de/?type=1533906435', $subject->buildSitemapUrl('https://www.example.com/de/', '?type=1533906435'));
        self::assertSame('https://www.example.com/de/sitemap.xml', $subject->buildSitemapUrl('https://www.example.com/de', 'sitemap.xml'));
    }

    private function discoveryAnswering(int $status, string $html): LanguageSitemapDiscovery
    {
        $redirectChainFollower = self::createStub(RedirectChainFollower::class);
        $redirectChainFollower->method('follow')->willReturn(
            new RedirectChain([['url' => 'https://www.example.com/', 'status' => $status]], $html),
        );

        return new LanguageSitemapDiscovery($redirectChainFollower);
    }
}
