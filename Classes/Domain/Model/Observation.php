<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Model;

use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Utility\RowValue;

/**
 * One requested path on one environment within a migration check run, as
 * stored in tx_otwebsitecheck_domain_model_observation.
 */
final readonly class Observation
{
    public const string ROLE_REFERENCE = 'reference';
    public const string ROLE_TARGET = 'target';
    public const string ROLE_TARGET_SITEMAP = 'targetSitemap';

    /**
     * @param list<array{url: string, status: int}> $redirectChain
     * @param list<string> $warnings
     */
    public function __construct(
        public int $uid,
        public string $runLabel,
        public string $environment,
        public string $role,
        public string $sitemapGroup,
        public string $requestedUrl,
        public string $requestedPath,
        public int $firstStatus,
        public string $finalUrl,
        public string $finalPath,
        public int $finalStatus,
        public int $hopCount,
        public array $redirectChain,
        public string $abortReason,
        public PageIdentity $identity,
        public string $verdict = '',
        public array $warnings = [],
        public string $suggestedTarget = '',
        public bool $reviewed = false,
        public string $note = '',
        public int $checkedAt = 0,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            uid: RowValue::int($row, 'uid'),
            runLabel: RowValue::string($row, 'run_label'),
            environment: RowValue::string($row, 'environment'),
            role: RowValue::string($row, 'role'),
            sitemapGroup: RowValue::string($row, 'sitemap_group'),
            requestedUrl: RowValue::string($row, 'requested_url'),
            requestedPath: RowValue::string($row, 'requested_path'),
            firstStatus: RowValue::int($row, 'first_status'),
            finalUrl: RowValue::string($row, 'final_url'),
            finalPath: RowValue::string($row, 'final_path'),
            finalStatus: RowValue::int($row, 'final_status'),
            hopCount: RowValue::int($row, 'hop_count'),
            redirectChain: self::decodeRedirectChain(RowValue::string($row, 'redirect_chain')),
            abortReason: RowValue::string($row, 'abort_reason'),
            identity: new PageIdentity(
                RowValue::int($row, 'page_uid'),
                RowValue::string($row, 'language'),
                RowValue::string($row, 'record_table'),
                RowValue::int($row, 'record_uid'),
            ),
            verdict: RowValue::string($row, 'verdict'),
            warnings: array_values(array_filter(explode(',', RowValue::string($row, 'warnings')))),
            suggestedTarget: RowValue::string($row, 'suggested_target'),
            reviewed: RowValue::int($row, 'reviewed') === 1,
            note: RowValue::string($row, 'note'),
            checkedAt: RowValue::int($row, 'checked_at'),
        );
    }

    /**
     * @return list<array{url: string, status: int}>
     */
    private static function decodeRedirectChain(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $steps = [];
        foreach ($decoded as $step) {
            if (!is_array($step)) {
                continue;
            }
            $url = $step['url'] ?? null;
            $status = $step['status'] ?? null;
            $steps[] = [
                'url' => is_string($url) ? $url : '',
                'status' => is_int($status) ? $status : 0,
            ];
        }

        return $steps;
    }
}
