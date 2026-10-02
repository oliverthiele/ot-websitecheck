<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageMetadata;
use OliverThiele\OtWebsitecheck\Service\MetadataAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Detail views that inherit the metadata of their detail page are the finding
 * the backend cannot show.
 */
final class MetadataAnalyzerTest extends UnitTestCase
{
    #[Test]
    public function urlsOfOnePageWithTheSameTitleShareTheirMetadata(): void
    {
        $results = (new MetadataAnalyzer())->analyze([
            $this->row(1, '/news/first', 30, new PageMetadata('News', 'Our news', '', '', '', 'https://www.example.com/a.jpg')),
            $this->row(2, '/news/second', 30, new PageMetadata('News', 'Our news', '', '', '', 'https://www.example.com/a.jpg')),
            $this->row(3, '/about/', 10, new PageMetadata('News', 'About us', '', '', '', 'https://www.example.com/a.jpg')),
        ]);

        self::assertSame([MetadataAnalyzer::FINDING_SHARED], $results[1]);
        self::assertSame([MetadataAnalyzer::FINDING_SHARED], $results[2]);
        // The same title on another page is no shared detail view.
        self::assertSame([], $results[3]);
    }

    #[Test]
    public function missingDescriptionImageAndNoindexAreFound(): void
    {
        $results = (new MetadataAnalyzer())->analyze([
            $this->row(1, '/hidden/', 10, new PageMetadata('Hidden', '', 'noindex, nofollow')),
        ]);

        self::assertSame([
            MetadataAnalyzer::FINDING_DESCRIPTION_MISSING,
            MetadataAnalyzer::FINDING_OPEN_GRAPH_IMAGE_MISSING,
            MetadataAnalyzer::FINDING_LISTED_BUT_NOINDEX,
        ], $results[1]);
    }

    /**
     * @return array{uid: int, url: string, pageUid: int, metadata: PageMetadata}
     */
    private function row(int $uid, string $path, int $pageUid, PageMetadata $metadata): array
    {
        return ['uid' => $uid, 'url' => 'https://www.example.com' . $path, 'pageUid' => $pageUid, 'metadata' => $metadata];
    }
}
