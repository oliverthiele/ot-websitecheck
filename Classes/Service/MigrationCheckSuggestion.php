<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\SnapshotEnvironment;

/**
 * Suggests which two snapshots a migration check should compare.
 *
 * Reference: the state search engines know — a locked live snapshot first,
 * then the newest live one. Target: the newest snapshot of the environment
 * closest to the next go-live (staging, then development, then local), and
 * only without any of those a live snapshot newer than the reference. A newer
 * live snapshot alone does not mean a relaunch went live — scheduled imports
 * take one every day. Without environments: the oldest snapshot against the
 * newest one of another host.
 */
class MigrationCheckSuggestion
{
    /**
     * @param list<SitemapSnapshot> $snapshots
     * @return array{reference: ?SitemapSnapshot, target: ?SitemapSnapshot}
     */
    public function suggest(array $snapshots): array
    {
        $snapshots = array_values(array_filter($snapshots, static fn(SitemapSnapshot $snapshot): bool => $snapshot->isComplete()));
        // Newest first; the same moment is ordered by uid.
        usort($snapshots, static fn(SitemapSnapshot $left, SitemapSnapshot $right): int => [$right->fetchedAt, $right->uid] <=> [$left->fetchedAt, $left->uid]);

        $live = $this->ofEnvironment($snapshots, SnapshotEnvironment::Live);
        $lockedLive = array_values(array_filter($live, static fn(SitemapSnapshot $snapshot): bool => $snapshot->locked));
        $reference = $lockedLive[0] ?? $live[0] ?? null;

        if ($reference === null) {
            $reference = $snapshots[count($snapshots) - 1] ?? null;
            if ($reference === null) {
                return ['reference' => null, 'target' => null];
            }

            return ['reference' => $reference, 'target' => $this->newestOtherHost($snapshots, $reference)];
        }

        $target = null;
        foreach (SnapshotEnvironment::TARGET_ORDER as $environment) {
            $target = $this->ofEnvironment($snapshots, $environment)[0] ?? null;
            if ($target !== null) {
                break;
            }
        }
        $target ??= array_values(array_filter(
            $live,
            static fn(SitemapSnapshot $snapshot): bool => $snapshot->uid !== $reference->uid && $snapshot->fetchedAt > $reference->fetchedAt,
        ))[0] ?? null;

        return ['reference' => $reference, 'target' => $target ?? $this->newestOtherHost($snapshots, $reference)];
    }

    /**
     * @param list<SitemapSnapshot> $snapshots newest first
     * @return list<SitemapSnapshot> newest first
     */
    private function ofEnvironment(array $snapshots, SnapshotEnvironment $environment): array
    {
        return array_values(array_filter($snapshots, static fn(SitemapSnapshot $snapshot): bool => $snapshot->environment === $environment->value));
    }

    /**
     * @param list<SitemapSnapshot> $snapshots newest first
     */
    private function newestOtherHost(array $snapshots, SitemapSnapshot $reference): ?SitemapSnapshot
    {
        $referenceHost = parse_url($reference->startUrl, PHP_URL_HOST);
        foreach ($snapshots as $snapshot) {
            if ($snapshot->uid !== $reference->uid && parse_url($snapshot->startUrl, PHP_URL_HOST) !== $referenceHost) {
                return $snapshot;
            }
        }

        return null;
    }
}
