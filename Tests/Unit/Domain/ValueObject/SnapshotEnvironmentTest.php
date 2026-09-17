<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Domain\ValueObject;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SnapshotEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SnapshotEnvironmentTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, SnapshotEnvironment|null}>
     */
    public static function baseConditions(): iterable
    {
        yield 'production' => ['applicationContext == "Production"', SnapshotEnvironment::Live];
        yield 'production sub-context' => ['applicationContext == "Production/Live"', SnapshotEnvironment::Live];
        yield 'staging' => ['applicationContext == "Production/Staging"', SnapshotEnvironment::Staging];
        yield 'stage, single quotes' => ["applicationContext == 'Production/Stage'", SnapshotEnvironment::Staging];
        yield 'development' => ['applicationContext == "Development"', SnapshotEnvironment::Development];
        yield 'local' => ['applicationContext == "Development/Local"', SnapshotEnvironment::Local];
        yield 'ddev' => ['applicationContext == "Development/Ddev"', SnapshotEnvironment::Local];
        yield 'testing' => ['applicationContext == "Testing"', null];
        yield 'other expression' => ['applicationContext matches "/^Development/"', null];
        yield 'combined condition' => ['applicationContext == "Production" && request.getHeader("x")', null];
        yield 'empty' => ['', null];
    }

    #[Test]
    #[DataProvider('baseConditions')]
    public function baseConditionNamesItsEnvironment(string $condition, ?SnapshotEnvironment $expected): void
    {
        self::assertSame($expected, SnapshotEnvironment::fromBaseCondition($condition));
    }
}
