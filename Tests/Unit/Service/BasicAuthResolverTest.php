<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Service\BasicAuthResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BasicAuthResolverTest extends UnitTestCase
{
    private const string PREFIX = 'WEBSITECHECK_TEST_BASIC_AUTH';

    protected function tearDown(): void
    {
        foreach (['_USER', '_PASS'] as $suffix) {
            unset($_ENV[self::PREFIX . $suffix]);
            putenv(self::PREFIX . $suffix);
        }
        parent::tearDown();
    }

    #[Test]
    public function optionValueWinsOverTheEnvironment(): void
    {
        $_ENV[self::PREFIX . '_USER'] = 'environment-user';
        $_ENV[self::PREFIX . '_PASS'] = 'environment-pass';

        self::assertSame(['auth' => ['user', 'pa:ss']], (new BasicAuthResolver())->buildRequestOptions('user:pa:ss', self::PREFIX));
    }

    #[Test]
    public function variablesAreReadFromEnv(): void
    {
        $_ENV[self::PREFIX . '_USER'] = 'user';
        $_ENV[self::PREFIX . '_PASS'] = 'secret';

        self::assertSame(['auth' => ['user', 'secret']], (new BasicAuthResolver())->buildRequestOptions('', self::PREFIX));
    }

    #[Test]
    public function variablesOfTheProcessEnvironmentAreReadWhenEnvLacksThem(): void
    {
        // variables_order without "E": the process environment only reaches getenv().
        putenv(self::PREFIX . '_USER=user');
        putenv(self::PREFIX . '_PASS=secret');

        self::assertSame(['auth' => ['user', 'secret']], (new BasicAuthResolver())->buildRequestOptions('', self::PREFIX));
    }

    #[Test]
    public function incompleteCredentialsGiveNoAuth(): void
    {
        putenv(self::PREFIX . '_USER=user');

        self::assertSame([], (new BasicAuthResolver())->buildRequestOptions('', self::PREFIX));
        self::assertSame([], (new BasicAuthResolver())->buildRequestOptions('no-colon', self::PREFIX));
    }
}
