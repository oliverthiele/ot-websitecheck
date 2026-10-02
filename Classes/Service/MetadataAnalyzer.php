<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageMetadata;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

/**
 * Judges the metadata of the pages a status check read. One finding needs
 * the other rows: URLs of the same page that share a title or description —
 * the detail views of a plugin that sets no metadata of its own.
 */
class MetadataAnalyzer
{
    public const string FINDING_SHARED = 'metaShared';
    public const string FINDING_DESCRIPTION_MISSING = 'metaDescriptionMissing';
    public const string FINDING_OPEN_GRAPH_IMAGE_MISSING = 'openGraphImageMissing';
    public const string FINDING_LISTED_BUT_NOINDEX = 'listedButNoindex';
    public const string FINDING_BREADCRUMB_ITEM_BROKEN = 'breadcrumbItemBroken';
    public const string FINDING_BREADCRUMB_ITEM_REDIRECTS = 'breadcrumbItemRedirects';

    public const array FINDINGS = [
        self::FINDING_SHARED,
        self::FINDING_DESCRIPTION_MISSING,
        self::FINDING_OPEN_GRAPH_IMAGE_MISSING,
        self::FINDING_LISTED_BUT_NOINDEX,
        self::FINDING_BREADCRUMB_ITEM_BROKEN,
        self::FINDING_BREADCRUMB_ITEM_REDIRECTS,
    ];

    /**
     * What a URL a breadcrumb links to does, see analyze().
     */
    public const string URL_BROKEN = 'broken';
    public const string URL_REDIRECTS = 'redirects';

    /**
     * @param list<array{uid: int, url: string, pageUid: int, metadata: PageMetadata}> $rows the working pages of one environment
     * @param array<string, string> $urlStates comparable path => URL_BROKEN or URL_REDIRECTS, for the URLs known not to answer
     *                                         directly; a breadcrumb item without an entry is taken as working
     * @return array<int, list<string>> uid => findings
     */
    public function analyze(array $rows, array $urlStates = []): array
    {
        $urlsByShared = [];
        foreach ($rows as $row) {
            if ($row['pageUid'] <= 0) {
                continue;
            }
            foreach (['title' => $row['metadata']->title, 'description' => $row['metadata']->description] as $field => $value) {
                if ($value !== '') {
                    $urlsByShared[$row['pageUid'] . '|' . $field . '|' . $value][$row['url']] = true;
                }
            }
        }

        $findings = [];
        foreach ($rows as $row) {
            $metadata = $row['metadata'];
            $rowFindings = [];
            foreach (['title' => $metadata->title, 'description' => $metadata->description] as $field => $value) {
                if ($value !== '' && $row['pageUid'] > 0 && count($urlsByShared[$row['pageUid'] . '|' . $field . '|' . $value]) > 1) {
                    $rowFindings[] = self::FINDING_SHARED;
                    break;
                }
            }
            if ($metadata->description === '') {
                $rowFindings[] = self::FINDING_DESCRIPTION_MISSING;
            }
            if ($metadata->openGraphImage === '') {
                $rowFindings[] = self::FINDING_OPEN_GRAPH_IMAGE_MISSING;
            }
            // Every row is a URL of a sitemap, which lists what should be indexed.
            if ($metadata->isNoindex()) {
                $rowFindings[] = self::FINDING_LISTED_BUT_NOINDEX;
            }
            $itemStates = array_map(
                static fn(string $url): string => $urlStates[UrlUtility::comparablePath($url)] ?? '',
                self::findBreadcrumbUrlsOnHost($metadata, $row['url']),
            );
            if (in_array(self::URL_BROKEN, $itemStates, true)) {
                $rowFindings[] = self::FINDING_BREADCRUMB_ITEM_BROKEN;
            }
            if (in_array(self::URL_REDIRECTS, $itemStates, true)) {
                $rowFindings[] = self::FINDING_BREADCRUMB_ITEM_REDIRECTS;
            }
            $findings[$row['uid']] = $rowFindings;
        }

        return $findings;
    }

    /**
     * The URLs a page's breadcrumb links to on its own host — the ones the
     * results of the environment can say something about.
     *
     * @return list<string>
     */
    public static function findBreadcrumbUrlsOnHost(PageMetadata $metadata, string $pageUrl): array
    {
        $host = strtolower((string)parse_url($pageUrl, PHP_URL_HOST));
        $urls = [];
        foreach ($metadata->breadcrumb as $item) {
            if ($item['url'] !== '' && strtolower((string)parse_url($item['url'], PHP_URL_HOST)) === $host) {
                $urls[] = $item['url'];
            }
        }

        return $urls;
    }
}
