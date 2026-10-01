<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Utility;

use OliverThiele\OtWebsitecheck\Utility\GzipUtility;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A small compressed file can expand to any size, so decoding stops at a limit
 * instead of trusting the input.
 */
final class GzipUtilityTest extends UnitTestCase
{
    #[Test]
    public function decodesGzipWithinTheLimit(): void
    {
        $xml = str_repeat('<url><loc>https://www.example.com/</loc></url>', 1000);

        self::assertSame($xml, GzipUtility::decode((string)gzencode($xml), strlen($xml)));
    }

    #[Test]
    public function refusesDataThatExpandsBeyondTheLimit(): void
    {
        $compressed = (string)gzencode(str_repeat('a', 1_000_000), 9);

        self::assertLessThan(10_000, strlen($compressed));
        self::assertNull(GzipUtility::decode($compressed, 999_999));
    }

    #[Test]
    public function refusesCorruptData(): void
    {
        self::assertNull(GzipUtility::decode("\x1f\x8b" . 'not really gzip', 1_000_000));
    }
}
