<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Service;

use OliverThiele\OtWebsitecheck\Domain\Repository\SitemapSnapshotRepository;

/**
 * The snapshots a command form offers: only complete ones — every check
 * refuses a snapshot whose import did not finish.
 */
class SnapshotOptionsProvider
{
    public function __construct(
        private readonly SitemapSnapshotRepository $sitemapSnapshotRepository,
    ) {
    }

    /**
     * @return list<array{uid: int, label: string, host: string, environment: string, locked: bool, fetchedAt: int}> newest first
     */
    public function getCompleteSnapshots(): array
    {
        $options = [];
        foreach ($this->sitemapSnapshotRepository->findAll() as $snapshot) {
            if (!$snapshot->isComplete()) {
                continue;
            }
            $host = parse_url($snapshot->startUrl, PHP_URL_HOST);
            $options[] = [
                'uid' => $snapshot->uid,
                'label' => $snapshot->label,
                'host' => is_string($host) ? $host : '',
                'environment' => $snapshot->environment,
                'locked' => $snapshot->locked,
                'fetchedAt' => $snapshot->fetchedAt,
            ];
        }

        return $options;
    }
}
