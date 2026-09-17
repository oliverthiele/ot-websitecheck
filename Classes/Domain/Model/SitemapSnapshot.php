<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Model;

use OliverThiele\OtWebsitecheck\Utility\RowValue;

/**
 * The sitemaps of every language of one site as they were fetched at one point
 * in time, as stored in tx_otwebsitecheck_domain_model_sitemapsnapshot.
 */
final readonly class SitemapSnapshot
{
    public const string STATUS_IMPORTING = 'importing';
    public const string STATUS_COMPLETE = 'complete';

    public function __construct(
        public int $uid,
        public string $label,
        public string $startUrl,
        public int $fetchedAt,
        public string $status = self::STATUS_COMPLETE,
        public string $note = '',
    ) {
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: RowValue::int($row, 'uid'),
            label: RowValue::string($row, 'label'),
            startUrl: RowValue::string($row, 'start_url'),
            fetchedAt: RowValue::int($row, 'fetched_at'),
            status: RowValue::string($row, 'status'),
            note: RowValue::string($row, 'note'),
        );
    }
}
