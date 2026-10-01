<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Utility;

use OliverThiele\OtWebsitecheck\Utility\UrlUtility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Basic Auth credentials are only sent where they were given for — never to
 * a host a sitemap or a stored snapshot happens to name.
 */
final class UrlUtilityTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function credentialCasesProvider(): array
    {
        return [
            'same origin' => ['https://www.example.com/', 'https://www.example.com/de/page/', true],
            'host differs only in case' => ['https://www.example.com/', 'https://WWW.example.com/', true],
            'explicit default port' => ['https://www.example.com/', 'https://www.example.com:443/', true],
            'upgrade to https' => ['http://staging.example.com/', 'https://staging.example.com/', true],
            'other host' => ['https://www.example.com/', 'https://other.example.org/', false],
            'subdomain' => ['https://example.com/', 'https://www.example.com/', false],
            'other port' => ['https://www.example.com/', 'https://www.example.com:8443/', false],
            'step down to http' => ['https://www.example.com/', 'http://www.example.com/', false],
            'malformed url' => ['https://www.example.com/', 'https://:80', false],
        ];
    }

    #[Test]
    #[DataProvider('credentialCasesProvider')]
    public function sharesCredentialsOnlyWithinTheSameOrigin(string $authorizedUrl, string $url, bool $expected): void
    {
        self::assertSame($expected, UrlUtility::sharesCredentials($authorizedUrl, $url));
    }

    #[Test]
    public function requestOptionsForKeepsAuthForAnAuthorizedUrlAndOtherOptionsAlways(): void
    {
        $requestOptions = ['auth' => ['user', 'secret'], 'timeout' => 5];
        $authorizedUrls = ['https://www.example.com/', 'https://www.example.org/en/sitemap.xml'];

        self::assertSame($requestOptions, UrlUtility::requestOptionsFor($requestOptions, 'https://www.example.org/en/', $authorizedUrls));
        self::assertSame(['timeout' => 5], UrlUtility::requestOptionsFor($requestOptions, 'https://attacker.example.net/', $authorizedUrls));
        self::assertSame(['timeout' => 5], UrlUtility::requestOptionsFor($requestOptions, 'https://www.example.com/', []));
    }
}
