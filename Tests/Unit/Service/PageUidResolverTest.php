<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\PageUidResolver;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\RouteResultInterface;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The router is replaced by a slug table, so these tests pin down which path
 * and language the resolver hands over — the part SiteMatcher does in a real
 * request and the resolver has to reproduce.
 */
final class PageUidResolverTest extends UnitTestCase
{
    /**
     * Language id => slug below the language base => page uid.
     */
    private const array SLUGS = [
        0 => ['' => 1, 'imprint' => 10, 'details' => 20],
        1 => ['' => 1, 'impressum' => 10],
    ];

    #[Test]
    public function pathInTheDefaultLanguageResolves(): void
    {
        self::assertSame(10, $this->resolverFor('/')->resolve('https://www.example.com/imprint'));
    }

    #[Test]
    public function languageBaseIsStrippedBeforeRouting(): void
    {
        self::assertSame(10, $this->resolverFor('/')->resolve('https://www.example.com/de/impressum'));
    }

    #[Test]
    public function rootOfATranslatedLanguageResolvesWithAndWithoutTrailingSlash(): void
    {
        $subject = $this->resolverFor('/');

        self::assertSame(1, $subject->resolve('https://www.example.com/de/'));
        self::assertSame(1, $subject->resolve('https://www.example.com/de'));
    }

    #[Test]
    public function languageBaseOnlyMatchesAtASegmentBoundary(): void
    {
        self::assertSame(20, $this->resolverFor('/')->resolve('https://www.example.com/details'));
    }

    #[Test]
    public function siteBelowASubdirectoryResolvesInEveryLanguage(): void
    {
        $subject = $this->resolverFor('/shop/');

        self::assertSame(10, $subject->resolve('https://www.example.com/shop/imprint'));
        self::assertSame(10, $subject->resolve('https://www.example.com/shop/de/impressum'));
        self::assertNull($subject->resolve('https://www.example.com/imprint'));
        self::assertNull($subject->resolve('https://www.example.com/shopping/imprint'));
    }

    #[Test]
    public function unknownSlugResolvesToNull(): void
    {
        self::assertNull($this->resolverFor('/')->resolve('https://www.example.com/de/unknown'));
    }

    private function resolverFor(string $siteBasePath): PageUidResolver
    {
        $router = self::createStub(RouterInterface::class);
        $router->method('matchRequest')->willReturnCallback(
            static function (ServerRequestInterface $request, ?RouteResultInterface $previousResult = null): RouteResultInterface {
                if (!$previousResult instanceof SiteRouteResult || $previousResult->getLanguage() === null) {
                    throw new RouteNotFoundException('No site route result', 1789700000);
                }
                $pageUid = self::SLUGS[$previousResult->getLanguage()->getLanguageId()][$previousResult->getTail()] ?? null;
                if ($pageUid === null) {
                    throw new RouteNotFoundException('Unknown slug', 1789700001);
                }

                return new PageArguments($pageUid, '0', []);
            },
        );

        // Declared default first, as in a site configuration; the resolver has to try "/de/" before "/".
        $languages = [
            new SiteLanguage(0, 'en_US.UTF-8', new Uri('https://www.example.com' . $siteBasePath), []),
            new SiteLanguage(1, 'de_DE.UTF-8', new Uri('https://www.example.com' . $siteBasePath . 'de/'), []),
        ];

        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://www.example.com' . $siteBasePath));
        $site->method('getAllLanguages')->willReturn($languages);
        $site->method('getRouter')->willReturn($router);

        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['main' => $site]);

        return new PageUidResolver($siteFinder);
    }
}
