<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\BackendPageLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The migration check knows a page's language only as the value of its
 * <html lang>; the page module needs the uid of the site language.
 */
final class BackendPageLinksTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function languageTags(): array
    {
        return [
            'locale with code set' => ['de-de', 0],
            'locale without region, by hreflang' => ['en-gb', 1],
            'language code only' => ['fr', 2],
            'region of a language code' => ['fr-ch', 2],
            'unknown language' => ['es-es', null],
            'no language' => ['', null],
        ];
    }

    #[Test]
    #[DataProvider('languageTags')]
    public function languageTagIsMatchedToTheSiteLanguage(string $languageTag, ?int $expected): void
    {
        self::assertSame($expected, $this->createSubject()->findLanguageId(1, $languageTag));
    }

    #[Test]
    public function pageOnAnotherHostIsLinkedIntoTheBackendOfThatHost(): void
    {
        $link = $this->createSubject()->forPageOfUrl('https://www.example.com/en/about/', 56, 1, $this->backendRequest());

        self::assertSame([
            'url' => 'https://www.example.com/typo3/module/web/layout?id=56&languages%5B0%5D=1',
            'host' => 'www.example.com',
        ], $link);
    }

    #[Test]
    public function unknownLanguageLeavesTheSelectionToTheBackend(): void
    {
        $link = $this->createSubject()->forPageOfUrl('https://www.example.com:8443/about/', 56, null, $this->backendRequest());

        self::assertSame('https://www.example.com:8443/typo3/module/web/layout?id=56', $link['url']);
    }

    #[Test]
    public function resultWithoutPageOrWebAddressGetsNoLink(): void
    {
        self::assertSame(['url' => '', 'host' => ''], $this->createSubject()->forPageOfUrl('https://www.example.com/', 0, null, $this->backendRequest()));
        self::assertSame(['url' => '', 'host' => ''], $this->createSubject()->forPageOfUrl('javascript:alert(1)', 5, null, $this->backendRequest()));
    }

    #[Test]
    public function languageIsFoundThroughTheSiteOfTheUrl(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));
        $siteFinder->method('getAllSites')->willReturn(['main' => $this->createSite()]);
        $subject = new BackendPageLinks(self::createStub(UriBuilder::class), $siteFinder, self::createStub(TcaSchemaFactory::class), self::createStub(BackendEntryPointResolver::class), self::createStub(ModuleProvider::class));

        self::assertSame(1, $subject->findLanguageId(56, 'en-gb', 'https://www.example.com/en/about/'));
        self::assertNull($subject->findLanguageId(56, 'en-gb', 'https://other.example.org/en/about/'));
    }

    #[Test]
    public function pageOutsideEverySiteHasNoLanguage(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));
        $siteFinder->method('getAllSites')->willReturn([]);
        $subject = new BackendPageLinks(self::createStub(UriBuilder::class), $siteFinder, self::createStub(TcaSchemaFactory::class), self::createStub(BackendEntryPointResolver::class), self::createStub(ModuleProvider::class));

        self::assertNull($subject->findLanguageId(99, 'de-de'));
        self::assertSame('1', $subject->findLanguageTitle(99, 1));
    }

    #[Test]
    public function languageTitleIsTheOneOfTheSite(): void
    {
        self::assertSame('English', $this->createSubject()->findLanguageTitle(1, 1));
    }

    private function createSubject(): BackendPageLinks
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($this->createSite());
        $entryPointResolver = self::createStub(BackendEntryPointResolver::class);
        $entryPointResolver->method('getPathFromRequest')->willReturn('/typo3/');
        $module = self::createStub(ModuleInterface::class);
        $module->method('getPath')->willReturn('/module/web/layout');
        $moduleProvider = self::createStub(ModuleProvider::class);
        $moduleProvider->method('getModule')->willReturn($module);

        return new BackendPageLinks(self::createStub(UriBuilder::class), $siteFinder, self::createStub(TcaSchemaFactory::class), $entryPointResolver, $moduleProvider);
    }

    private function backendRequest(): ServerRequestInterface
    {
        $request = new ServerRequest('https://backend.example.net/typo3/module/site/websitecheck/status', 'GET', null, [], ['HTTP_HOST' => 'backend.example.net', 'HTTPS' => 'on']);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function createSite(): Site
    {
        return new Site('main', 1, [
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'hreflang' => 'de-DE', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en', 'hreflang' => 'en-GB', 'base' => '/en/'],
                ['languageId' => 2, 'title' => 'Français', 'locale' => 'fr_FR', 'base' => '/fr/'],
            ],
        ]);
    }
}
