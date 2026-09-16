<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\IdentityPatterns;
use OliverThiele\OtWebsitecheck\Service\IdentityExtractor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class IdentityExtractorTest extends UnitTestCase
{
    #[Test]
    public function defaultMarkersAreRead(): void
    {
        $html = '<!DOCTYPE html><html lang="de-DE"><head>'
            . '<meta name="websitecheck:record" content="tx_myextension_domain_model_item:42">'
            . '</head><body id="page-17" class="page"></body></html>';

        $identity = (new IdentityExtractor())->extract($html, new IdentityPatterns());

        self::assertSame(17, $identity->pageUid);
        self::assertSame('de-de', $identity->language, 'the language is normalised to lower case');
        self::assertSame('tx_myextension_domain_model_item', $identity->recordTable);
        self::assertSame(42, $identity->recordUid);
    }

    #[Test]
    public function shortPageIdAndMissingRecordAreHandled(): void
    {
        $identity = (new IdentityExtractor())->extract('<html lang="en"><body id="p5">', new IdentityPatterns());

        self::assertSame(5, $identity->pageUid);
        self::assertFalse($identity->hasRecord());
    }
}
