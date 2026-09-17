<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\SitemapDocument;

/**
 * Arranges the sitemap files of one snapshot for the module: one row per
 * language with its URL count per sitemap group, and the sitemap files of
 * each group for the details of that row.
 *
 * Languages of one site usually share their groups, so a group that one
 * language lacks while others have it is marked as missing — that is what
 * has to catch the eye before a relaunch.
 *
 * The sitemap index of a language — or the file that should have been one
 * but could not be read — is no group of its own: it only lists sitemaps and
 * never page URLs.
 *
 * @phpstan-type SitemapFile array{uid: int, url: string, type: string, httpStatus: int, urlCount: int, failed: bool}
 * @phpstan-type GroupDetails array{name: string, sitemapCount: int, urlCount: int, failedCount: int, files: list<SitemapFile>}
 * @phpstan-type GroupCell array{name: string, urlCount: int, missing: bool}
 * @phpstan-type LanguageRow array{language: string, indexFiles: list<SitemapFile>, indexFailed: bool, sitemapCount: int, urlCount: int, failedCount: int, missingCount: int, cells: list<GroupCell>, groups: list<GroupDetails>}
 * @phpstan-type SnapshotOverview array{groupNames: list<string>, languages: list<LanguageRow>, languageCount: int, indexCount: int, sitemapCount: int, urlCount: int, failedCount: int}
 */
class SnapshotOverviewBuilder
{
    /**
     * @param list<array{uid: int, language: string, url: string, parentUrl: string, sitemapGroup: string, type: string, httpStatus: int, urlCount: int}> $documents
     * @return SnapshotOverview
     */
    public function build(array $documents): array
    {
        $indexFilesByLanguage = [];
        $groupFilesByLanguage = [];
        $groupNames = [];
        foreach ($documents as $document) {
            $failed = !SitemapDocument::isUsableType($document['type']);
            $file = [
                'uid' => $document['uid'],
                'url' => $document['url'],
                'type' => $document['type'],
                'httpStatus' => $document['httpStatus'],
                'urlCount' => $document['urlCount'],
                'failed' => $failed,
            ];
            $language = $document['language'];
            $indexFilesByLanguage[$language] ??= [];
            $groupFilesByLanguage[$language] ??= [];

            $isRootThatFailed = $failed && $document['parentUrl'] === '';
            if ($document['type'] === SitemapDocument::TYPE_INDEX || $isRootThatFailed) {
                $indexFilesByLanguage[$language][] = $file;
                continue;
            }
            $groupFilesByLanguage[$language][$document['sitemapGroup']][] = $file;
            $groupNames[$document['sitemapGroup']] = true;
        }
        ksort($groupNames, SORT_STRING);
        $groupNames = array_map('strval', array_keys($groupNames));
        ksort($indexFilesByLanguage, SORT_STRING);

        $languages = [];
        foreach ($indexFilesByLanguage as $language => $indexFiles) {
            $languages[] = $this->buildLanguageRow((string)$language, $indexFiles, $groupFilesByLanguage[$language], $groupNames);
        }

        return [
            'groupNames' => $groupNames,
            'languages' => $languages,
            'languageCount' => count($languages),
            'indexCount' => array_sum(array_map(static fn(array $row): int => count($row['indexFiles']), $languages)),
            'sitemapCount' => array_sum(array_column($languages, 'sitemapCount')),
            'urlCount' => array_sum(array_column($languages, 'urlCount')),
            'failedCount' => array_sum(array_column($languages, 'failedCount')),
        ];
    }

    /**
     * @param list<SitemapFile> $indexFiles
     * @param array<array-key, list<SitemapFile>> $filesByGroup
     * @param list<string> $groupNames every group of the snapshot
     * @return LanguageRow
     */
    private function buildLanguageRow(string $language, array $indexFiles, array $filesByGroup, array $groupNames): array
    {
        $groups = [];
        $cells = [];
        $missingCount = 0;
        foreach ($groupNames as $groupName) {
            $files = $filesByGroup[$groupName] ?? [];
            $urlCount = array_sum(array_column($files, 'urlCount'));
            $missing = $urlCount === 0;
            $missingCount += $missing ? 1 : 0;
            $cells[] = ['name' => $groupName, 'urlCount' => $urlCount, 'missing' => $missing];
            if ($files !== []) {
                $groups[] = [
                    'name' => $groupName,
                    'sitemapCount' => count($files),
                    'urlCount' => $urlCount,
                    'failedCount' => count(array_filter($files, static fn(array $file): bool => $file['failed'])),
                    'files' => $files,
                ];
            }
        }

        $indexFailedCount = count(array_filter($indexFiles, static fn(array $file): bool => $file['failed']));

        return [
            'language' => $language,
            'indexFiles' => $indexFiles,
            'indexFailed' => $indexFailedCount > 0,
            'sitemapCount' => array_sum(array_column($groups, 'sitemapCount')),
            'urlCount' => array_sum(array_column($groups, 'urlCount')),
            'failedCount' => $indexFailedCount + array_sum(array_column($groups, 'failedCount')),
            'missingCount' => $missingCount,
            'cells' => $cells,
            'groups' => $groups,
        ];
    }
}
