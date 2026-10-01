<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;

/**
 * Decides which sitemap snapshots a cleanup removes, so a scheduled import
 * does not pile up snapshots forever.
 *
 * Kept, whatever their age, complete or not:
 * - locked snapshots
 * - snapshots a migration check run compared — its results refer to them
 * - snapshots with a note — someone marked them as worth keeping
 *
 * Kept besides: the newest complete snapshots per start URL, up to the given
 * number. The snapshots kept for one of the reasons above do not count
 * towards it. Start URLs that differ only in the case of scheme and host or
 * in a trailing slash count as one.
 *
 * Removed besides: imports that never finished and are older than the given
 * point in time; an import still running is younger than that.
 */
class SnapshotRetentionPolicy
{
    /**
     * @param list<SitemapSnapshot> $snapshots
     * @param list<int> $protectedSnapshotUids Snapshots in use, e.g. by migration check runs.
     * @param int $keepPerStartUrl Complete snapshots kept per start URL; at least 1.
     * @param int $incompleteBefore Unix timestamp; incomplete snapshots fetched before it are removed.
     * @return list<SitemapSnapshot>
     */
    public function selectForDeletion(array $snapshots, array $protectedSnapshotUids, int $keepPerStartUrl, int $incompleteBefore): array
    {
        $keepPerStartUrl = max(1, $keepPerStartUrl);

        usort($snapshots, static fn(SitemapSnapshot $left, SitemapSnapshot $right): int => [$right->fetchedAt, $right->uid] <=> [$left->fetchedAt, $left->uid]);

        $selected = [];
        $completeCountByStartUrl = [];
        foreach ($snapshots as $snapshot) {
            if ($snapshot->locked
                || in_array($snapshot->uid, $protectedSnapshotUids, true)
                || trim($snapshot->note) !== ''
            ) {
                continue;
            }
            if (!$snapshot->isComplete()) {
                if ($snapshot->fetchedAt < $incompleteBefore) {
                    $selected[] = $snapshot;
                }
                continue;
            }

            $startUrl = $this->normalizeStartUrl($snapshot->startUrl);
            $position = $completeCountByStartUrl[$startUrl] = ($completeCountByStartUrl[$startUrl] ?? 0) + 1;
            if ($position > $keepPerStartUrl) {
                $selected[] = $snapshot;
            }
        }

        return $selected;
    }

    private function normalizeStartUrl(string $startUrl): string
    {
        $scheme = parse_url($startUrl, PHP_URL_SCHEME);
        $host = parse_url($startUrl, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host)) {
            return rtrim($startUrl, '/');
        }
        $prefix = $scheme . '://' . $host;
        // Only scheme and host are case-insensitive; the path keeps its case.
        $rest = substr($startUrl, strlen($prefix));

        return rtrim(strtolower($prefix) . $rest, '/');
    }
}
