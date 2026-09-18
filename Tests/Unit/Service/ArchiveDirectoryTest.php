<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Tests\Unit\Service;

use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use OliverThiele\OtWebsitecheck\Service\ArchiveDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Archives hold whole sitemaps and check results: the directory must stay
 * inside the project and out of reach of a browser.
 */
final class ArchiveDirectoryTest extends UnitTestCase
{
    private const string PROJECT = '/var/www/html';
    private const string PUBLIC = '/var/www/html/public';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedDirectories(): iterable
    {
        yield 'default' => ['data/websitecheck', '/var/www/html/data/websitecheck'];
        yield 'trailing slash and space' => ['data/websitecheck/ ', '/var/www/html/data/websitecheck'];
        yield 'var' => ['var/websitecheck', '/var/www/html/var/websitecheck'];
        yield 'name starting like public' => ['publications', '/var/www/html/publications'];
        yield 'empty switches off' => ['', ''];
    }

    #[Test]
    #[DataProvider('allowedDirectories')]
    public function allowedDirectoryIsResolvedInsideTheProject(string $configured, string $expected): void
    {
        self::assertSame($expected, ArchiveDirectory::resolve($configured, self::PROJECT, self::PUBLIC));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refusedDirectories(): iterable
    {
        yield 'leaves the project' => ['data/../../etc', 1789490302];
        yield 'parent directory' => ['..', 1789490302];
        yield 'public directory' => ['public', 1789490303];
        yield 'inside the public directory' => ['public/fileadmin/websitecheck', 1789490303];
        yield 'absolute path' => ['/data/websitecheck', 1789490301];
        yield 'windows drive' => ['C:/backups', 1789490301];
    }

    #[Test]
    #[DataProvider('refusedDirectories')]
    public function directoryOutsideTheProjectOrInPublicIsRefused(string $configured, int $code): void
    {
        $this->expectException(SnapshotArchiveException::class);
        $this->expectExceptionCode($code);

        ArchiveDirectory::resolve($configured, self::PROJECT, self::PUBLIC);
    }

    #[Test]
    public function fileStemKeepsOnlySafeCharacters(): void
    {
        self::assertSame('www-example-com-2026-09-17-10-33', ArchiveDirectory::buildFileStem('www.example.com 2026-09-17 10:33'));
        self::assertSame('run-live-berlin', ArchiveDirectory::buildFileStem('run-Live/../Berlin'));
        self::assertSame('archive', ArchiveDirectory::buildFileStem('äöü'));
    }
}
