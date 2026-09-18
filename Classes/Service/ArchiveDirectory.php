<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\ArchiveFile;
use OliverThiele\OtWebsitecheck\Exception\SnapshotArchiveException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The directory the backend modules save archives to and read them from, set
 * as "archiveDirectory" in the extension configuration.
 *
 * Only a path inside the project and outside the public directory is used:
 * an archive holds whole sitemaps and check results and must never be
 * reachable by URL. File names are generated here and checked on every
 * access, so a request can name nothing but an archive in this directory.
 */
class ArchiveDirectory
{
    public const string EXTENSION_KEY = 'ot_websitecheck';
    public const string FILE_SUFFIX = '.json.gz';

    /**
     * Used until the extension configuration has been saved once; an empty
     * value saved there switches saving off.
     */
    public const string DEFAULT_DIRECTORY = 'data/websitecheck';

    private const string FILE_NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*\.json\.gz$/';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /**
     * The configured directory as an absolute path, or '' when saving in the
     * modules is switched off.
     *
     * @throws SnapshotArchiveException when the configured path is not allowed
     */
    public function getPath(): string
    {
        try {
            $configured = $this->extensionConfiguration->get(self::EXTENSION_KEY, 'archiveDirectory');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            $configured = self::DEFAULT_DIRECTORY;
        }

        return self::resolve(is_string($configured) ? $configured : '', Environment::getProjectPath(), Environment::getPublicPath());
    }

    /**
     * @return string absolute path without trailing slash, '' for an empty setting
     * @throws SnapshotArchiveException when the path leaves the project or lies in the public directory
     */
    public static function resolve(string $configured, string $projectPath, string $publicPath): string
    {
        $relative = trim(str_replace('\\', '/', $configured), " \t/");
        if ($relative === '') {
            return '';
        }
        if (str_starts_with(trim($configured), '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw new SnapshotArchiveException(sprintf('The archive directory "%s" must be relative to the project root.', $configured), 1789490301);
        }
        if (in_array('..', explode('/', $relative), true)) {
            throw new SnapshotArchiveException(sprintf('The archive directory "%s" must not contain "..".', $configured), 1789490302);
        }

        $path = rtrim($projectPath, '/') . '/' . $relative;
        $publicPath = rtrim($publicPath, '/');
        if ($path === $publicPath || str_starts_with($path . '/', $publicPath . '/')) {
            throw new SnapshotArchiveException(sprintf('The archive directory "%s" lies in the public directory, where archives could be downloaded by anyone.', $configured), 1789490303);
        }

        return $path;
    }

    /**
     * @return list<ArchiveFile> newest first; none when the directory does not exist yet
     */
    public function listFiles(): array
    {
        $directory = $this->getPath();
        if ($directory === '' || !is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match(self::FILE_NAME_PATTERN, $name) !== 1 || !is_file($directory . '/' . $name)) {
                continue;
            }
            $files[] = new ArchiveFile($name, $directory . '/' . $name, (int)filesize($directory . '/' . $name), (int)filemtime($directory . '/' . $name));
        }
        usort($files, static fn(ArchiveFile $left, ArchiveFile $right): int => [$right->modifiedAt, $right->name] <=> [$left->modifiedAt, $left->name]);

        return $files;
    }

    /**
     * Writes a new file and never replaces an existing one.
     *
     * @param string $nameStem What the file is about, e.g. a snapshot label; made safe here.
     * @return ArchiveFile the written file
     * @throws SnapshotArchiveException when saving is off or the file cannot be written
     */
    public function write(string $nameStem, string $content, int $time): ArchiveFile
    {
        $directory = $this->getPath();
        if ($directory === '') {
            throw new SnapshotArchiveException('No archive directory is configured.', 1789490304);
        }
        if (!is_dir($directory)) {
            GeneralUtility::mkdir_deep($directory);
        }

        $baseName = self::buildFileStem($nameStem) . '_' . date('Y-m-d_His', $time);
        $name = $baseName . self::FILE_SUFFIX;
        for ($counter = 2; file_exists($directory . '/' . $name); $counter++) {
            $name = $baseName . '-' . $counter . self::FILE_SUFFIX;
        }
        if (file_put_contents($directory . '/' . $name, $content, LOCK_EX) === false) {
            throw new SnapshotArchiveException(sprintf('"%s" could not be written.', $name), 1789490305);
        }

        return new ArchiveFile($name, $directory . '/' . $name, strlen($content), $time);
    }

    /**
     * @throws SnapshotArchiveException when there is no such archive in the directory
     */
    public function find(string $name): ArchiveFile
    {
        foreach ($this->listFiles() as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }

        throw new SnapshotArchiveException(sprintf('There is no archive "%s".', $name), 1789490306);
    }

    public static function buildFileStem(string $text): string
    {
        $stem = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        return $stem !== '' ? substr($stem, 0, 80) : 'archive';
    }
}
