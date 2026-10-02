<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\MetadataExtractor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MetadataExtractorTest extends UnitTestCase
{
    #[Test]
    public function metadataIsReadAsTheBrowserShowsIt(): void
    {
        $html = '<html><head>'
            . "<title>\n  News &amp; more:  An article\n</title>"
            . '<meta name="description" content="A short summary.">'
            . '<META NAME="robots" CONTENT="noindex,follow">'
            . '<meta content="An article" property="og:title">'
            . "<meta property='og:description' content='Shared summary'>"
            . '<meta property="og:image" content="/fileadmin/article.jpg">'
            . '<meta property="og:image" content="/fileadmin/second.jpg">'
            . '</head><body></body></html>';

        $metadata = (new MetadataExtractor())->extract($html, 'https://www.example.com/news/article');

        self::assertSame('News & more: An article', $metadata->title);
        self::assertSame('A short summary.', $metadata->description);
        self::assertSame('noindex,follow', $metadata->robots);
        self::assertTrue($metadata->isNoindex());
        self::assertSame('An article', $metadata->openGraphTitle);
        self::assertSame('Shared summary', $metadata->openGraphDescription);
        self::assertSame('https://www.example.com/fileadmin/article.jpg', $metadata->openGraphImage);
    }

    #[Test]
    public function pageWithoutMetadataHasNone(): void
    {
        $metadata = (new MetadataExtractor())->extract('<html><head></head><body><title>Not in the head</title></body></html>', 'https://www.example.com/');

        self::assertSame('Not in the head', $metadata->title);
        self::assertSame('', $metadata->description);
        self::assertSame('', $metadata->openGraphImage);
        self::assertFalse($metadata->isNoindex());
    }

    #[Test]
    public function metadataSurvivesItsStorage(): void
    {
        $metadata = (new MetadataExtractor())->extract('<title>Ä title</title><meta name="description" content="Über uns">', 'https://www.example.com/');

        self::assertEquals($metadata, $metadata::fromJson($metadata->toJson()));
        self::assertSame('', (new MetadataExtractor())->extract('', 'https://www.example.com/')->toJson());
    }
}
