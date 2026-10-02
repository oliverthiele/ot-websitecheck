<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\BackendPageLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
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
    public function pageOutsideEverySiteHasNoLanguage(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new SiteNotFoundException('none', 1));
        $subject = new BackendPageLinks(self::createStub(UriBuilder::class), $siteFinder, self::createStub(TcaSchemaFactory::class));

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
        $site = new Site('main', 1, [
            'base' => 'https://www.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'hreflang' => 'de-DE', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en', 'hreflang' => 'en-GB', 'base' => '/en/'],
                ['languageId' => 2, 'title' => 'Français', 'locale' => 'fr_FR', 'base' => '/fr/'],
            ],
        ]);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        return new BackendPageLinks(self::createStub(UriBuilder::class), $siteFinder, self::createStub(TcaSchemaFactory::class));
    }
}
