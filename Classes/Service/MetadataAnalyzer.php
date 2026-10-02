<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageMetadata;

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

    public const array FINDINGS = [
        self::FINDING_SHARED,
        self::FINDING_DESCRIPTION_MISSING,
        self::FINDING_OPEN_GRAPH_IMAGE_MISSING,
        self::FINDING_LISTED_BUT_NOINDEX,
    ];

    /**
     * @param list<array{uid: int, url: string, pageUid: int, metadata: PageMetadata}> $rows the working pages of one environment
     * @return array<int, list<string>> uid => findings
     */
    public function analyze(array $rows): array
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
            $findings[$row['uid']] = $rowFindings;
        }

        return $findings;
    }
}
