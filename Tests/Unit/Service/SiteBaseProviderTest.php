<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\SiteBaseProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The import form only fetches hosts returned here — this list is the
 * boundary between "any configured environment" and "any URL on the network".
 */
final class SiteBaseProviderTest extends UnitTestCase
{
    #[Test]
    public function baseAndEveryBaseVariantAreListedRegardlessOfCondition(): void
    {
        $subject = $this->providerFor([
            'base' => 'https://www.example.com/',
            'baseVariants' => [
                ['base' => 'https://staging.example.com/', 'condition' => 'applicationContext == "Production/Staging"'],
                ['base' => 'https://example.ddev.site/', 'condition' => 'applicationContext == "Development"'],
            ],
        ]);

        self::assertSame(
            ['https://www.example.com/', 'https://staging.example.com/', 'https://example.ddev.site/'],
            array_map(static fn($base): string => $base->url, $subject->getBases()),
        );
    }

    #[Test]
    public function sitemapPathComesFromThePageTypeMapping(): void
    {
        $subject = $this->providerFor([
            'base' => 'https://www.example.com/',
            'routeEnhancers' => [
                'PageTypeSuffix' => ['type' => 'PageType', 'default' => '', 'map' => ['feed.xml' => 9818, 'sitemap.xml' => 1533906435]],
            ],
        ]);

        self::assertSame('sitemap.xml', $subject->getBases()[0]->sitemapPath);
    }

    #[Test]
    public function sitemapPathFallsBackToPageTypeArgumentWithoutMapping(): void
    {
        $subject = $this->providerFor(['base' => 'https://www.example.com/']);

        self::assertSame('?type=1533906435', $subject->getBases()[0]->sitemapPath);
    }

    #[Test]
    public function onlyHostsOfConfiguredBasesAreAllowed(): void
    {
        $subject = $this->providerFor([
            'base' => 'https://www.example.com/',
            'baseVariants' => [['base' => 'https://staging.example.com/', 'condition' => '']],
        ]);

        self::assertTrue($subject->isConfiguredHost('https://STAGING.example.com/de/sitemap.xml'));
        self::assertFalse($subject->isConfiguredHost('http://192.0.2.10/sitemap.xml'));
        self::assertFalse($subject->isConfiguredHost('not a url'));
        self::assertNull($subject->findByUrl('https://www.example.com/de/'));
    }

    #[Test]
    public function baseWithoutHostIsIgnored(): void
    {
        $subject = $this->providerFor(['base' => '/']);

        self::assertSame([], $subject->getBases());
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function providerFor(array $configuration): SiteBaseProvider
    {
        // A real Site evaluates the base variant conditions on construction, which needs the container.
        $site = self::createStub(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getConfiguration')->willReturn($configuration);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['main' => $site]);

        return new SiteBaseProvider($siteFinder, self::createStub(SetRegistry::class));
    }
}
