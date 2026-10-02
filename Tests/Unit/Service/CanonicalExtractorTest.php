<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\CanonicalExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The canonical decides whether a redirect or a sitemap entry points at the
 * URL a search engine indexes. A canonical read wrongly reports a correct
 * redirect as one to change.
 */
final class CanonicalExtractorTest extends UnitTestCase
{
    private CanonicalExtractor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new CanonicalExtractor();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalTags(): array
    {
        return [
            'absolute, as EXT:seo renders it' => ['<link rel="canonical" href="https://www.example.com/a/"/>', 'https://www.example.com/a/'],
            'relative to the root' => ['<link rel="canonical" href="/a/">', 'https://www.example.com/a/'],
            'relative to the page' => ['<link rel="canonical" href="../a/">', 'https://www.example.com/a/'],
            'href before rel, single quotes' => ["<link href='https://www.example.com/a/' rel='canonical'>", 'https://www.example.com/a/'],
            'unquoted, upper case' => ['<LINK REL=Canonical HREF=https://www.example.com/a/>', 'https://www.example.com/a/'],
            'entities in the query' => ['<link rel="canonical" href="https://www.example.com/a/?x=1&amp;y=2">', 'https://www.example.com/a/?x=1&y=2'],
            'several relations' => ['<link rel="alternate canonical" href="https://www.example.com/a/">', 'https://www.example.com/a/'],
        ];
    }

    #[Test]
    #[DataProvider('canonicalTags')]
    public function canonicalIsReadAndResolved(string $tag, string $expected): void
    {
        $html = '<html><head><title>B</title>' . $tag . '</head><body></body></html>';

        self::assertSame($expected, $this->subject->extract($html, 'https://www.example.com/b/'));
    }

    #[Test]
    public function otherLinkTagsAreSkipped(): void
    {
        $html = '<link rel="alternate" hreflang="de" href="https://www.example.com/de/">'
            . '<link rel="stylesheet" href="/style.css">'
            . '<link rel="canonical" href="https://www.example.com/a/">';

        self::assertSame('https://www.example.com/a/', $this->subject->extract($html, 'https://www.example.com/b/'));
    }

    #[Test]
    public function pageWithoutCanonicalHasNone(): void
    {
        self::assertSame('', $this->subject->extract('<html><head><link rel="stylesheet" href="/style.css"></head></html>', 'https://www.example.com/b/'));
        self::assertSame('', $this->subject->extract('<link rel="canonical" href="">', 'https://www.example.com/b/'));
    }

    #[Test]
    public function canonicalThatIsNoWebAddressIsIgnored(): void
    {
        self::assertSame('', $this->subject->extract('<link rel="canonical" href="javascript:alert(1)">', 'https://www.example.com/b/'));
    }

    #[Test]
    public function canonicalOfThePageItselfIsNotElsewhere(): void
    {
        self::assertFalse($this->subject->isElsewhere('https://www.example.com/a/', 'https://www.example.com/a/'));
        self::assertFalse($this->subject->isElsewhere('https://www.example.com/', 'https://www.example.com'));
        self::assertFalse($this->subject->isElsewhere('', 'https://www.example.com/a/'));
    }

    #[Test]
    public function canonicalOnAnotherHostWithTheSamePathIsNotElsewhere(): void
    {
        // A staging system that renders the live domain into its canonical.
        self::assertFalse($this->subject->isElsewhere('https://www.example.com/a/', 'https://staging.example.com/a/'));
    }

    #[Test]
    public function canonicalOfAnotherPathIsElsewhere(): void
    {
        self::assertTrue($this->subject->isElsewhere('https://www.example.com/a/', 'https://www.example.com/b/'));
        self::assertTrue($this->subject->isElsewhere('https://www.example.com/a/', 'https://www.example.com/a/?page=2'));
    }
}
