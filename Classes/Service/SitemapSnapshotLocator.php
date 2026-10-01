<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Model\SitemapSnapshot;
use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;

/**
 * Hands the checks the URLs of a stored sitemap snapshot — the only source of
 * URLs for every check of this extension.
 */
class SitemapSnapshotLocator
{
    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when there is no usable snapshot with this label
     */
    public function findCompleteSnapshot(string $label): SitemapSnapshot
    {
        if ($label === '') {
            throw new \InvalidArgumentException('A sitemap snapshot label is required.', 1789480001);
        }
        $snapshot = $this->sitemapSnapshotRepository->findByLabel($label);
        if ($snapshot === null) {
            throw new \InvalidArgumentException(sprintf('There is no sitemap snapshot named "%s".', $label), 1789480002);
        }
        if (!$snapshot->isComplete()) {
            throw new \InvalidArgumentException(sprintf('The import of sitemap snapshot "%s" did not finish.', $label), 1789480003);
        }

        return $snapshot;
    }

    /**
     * @param list<string> $groups Only URLs of these sitemap groups; all when empty.
     * @return array<string, string> page URL => sitemap group, in import order
     */
    public function findUrls(SitemapSnapshot $snapshot, array $groups = []): array
    {
        $urls = $this->sitemapSnapshotRepository->findUrls($snapshot->uid);
        if ($groups === []) {
            return $urls;
        }

        return array_filter($urls, static fn(string $group): bool => in_array($group, $groups, true));
    }

    /**
     * The URLs Basic Auth credentials for this snapshot are meant for: its start
     * URL and the sitemap of every language, i.e. what the import was given or
     * found on the start page. A page URL some sitemap lists on another host is
     * not among them, see UrlUtility::requestOptionsFor().
     *
     * @return list<string>
     */
    public function findAuthorizedUrls(SitemapSnapshot $snapshot): array
    {
        return array_values(array_unique([
            $snapshot->startUrl,
            ...$this->sitemapSnapshotRepository->findRootDocumentUrls($snapshot->uid),
        ]));
    }
}
