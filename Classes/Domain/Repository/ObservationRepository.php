<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use OliverThiele\OtWebsitecheck\Domain\Model\Observation;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\PageIdentity;
use OliverThiele\OtWebsitecheck\Domain\ValueObject\RedirectChain;
use OliverThiele\OtWebsitecheck\Utility\UrlUtility;

class ObservationRepository extends AbstractRepository
{
    public const string TABLE = 'tx_otwebsitecheck_domain_model_observation';

    /**
     * Stores what was observed for one requested path on one environment.
     * Verdict, warnings and the reviewed flag are left alone here — they
     * depend on the other rows of the run and are set by updateAnalysis().
     */
    public function storeObservation(
        string $runLabel,
        string $environment,
        string $role,
        string $sitemapGroup,
        RedirectChain $redirectChain,
        PageIdentity $identity,
        int $checkedAt,
    ): void {
        $requestedUrl = $redirectChain->getRequestedUrl();
        $requestedPath = UrlUtility::pathWithQuery($requestedUrl);
        $finalUrl = $redirectChain->getFinalUrl();

        $values = [
            'role' => $role,
            'sitemap_group' => $sitemapGroup,
            'requested_url' => $requestedUrl,
            'first_status' => $redirectChain->getFirstStatus(),
            'final_url' => $finalUrl,
            'final_path' => UrlUtility::pathWithQuery($finalUrl),
            'final_status' => $redirectChain->getFinalStatus(),
            'hop_count' => $redirectChain->getHopCount(),
            'redirect_chain' => json_encode($redirectChain->steps, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'abort_reason' => $redirectChain->abortReason,
            'page_uid' => $identity->pageUid,
            'language' => $identity->language,
            'record_table' => $identity->recordTable,
            'record_uid' => $identity->recordUid,
            'checked_at' => $checkedAt,
        ];

        $existingUid = $this->findUid($runLabel, $environment, $requestedPath);
        if ($existingUid === null) {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, $values + [
                'pid' => 0,
                'run_label' => $runLabel,
                'environment' => $environment,
                'requested_path' => $requestedPath,
                'verdict' => '',
                'warnings' => '',
                'suggested_target' => '',
                'reviewed' => 0,
                'note' => '',
            ]);
            return;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->update(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($existingUid, ParameterType::INTEGER)));
        foreach ($values as $field => $value) {
            $queryBuilder->set($field, $value, true, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * The reviewed flag and note survive a re-run as long as the verdict stays
     * the same; a changed verdict is a new finding and needs review again.
     *
     * @param list<string> $warnings
     */
    public function updateAnalysis(Observation $observation, string $verdict, array $warnings, string $suggestedTarget): void
    {
        $warningsValue = implode(',', $warnings);
        if ($observation->verdict === $verdict
            && implode(',', $observation->warnings) === $warningsValue
            && $observation->suggestedTarget === $suggestedTarget
        ) {
            return;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $queryBuilder->update(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($observation->uid, ParameterType::INTEGER)))
            ->set('verdict', $verdict)
            ->set('warnings', $warningsValue)
            ->set('suggested_target', $suggestedTarget);
        if ($observation->verdict !== $verdict) {
            $queryBuilder->set('reviewed', 0, true, ParameterType::INTEGER);
        }
        $queryBuilder->executeStatement();
    }

    /**
     * @return list<Observation>
     */
    public function findByRun(string $runLabel): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $rows = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->orderBy('requested_path', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): Observation => Observation::fromRow($row), $rows);
    }

    /**
     * @return list<string> Most recently checked run first — the one the module opens.
     */
    public function findDistinctRuns(): array
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $values = $queryBuilder->select('run_label')
            ->addSelectLiteral('MAX(checked_at) AS last_checked_at')
            ->from(self::TABLE)
            ->groupBy('run_label')
            ->orderBy('last_checked_at', 'DESC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $value): string => is_scalar($value) ? (string)$value : '', $values);
    }

    /**
     * @return bool|null The new "reviewed" state, or null if the record does not exist.
     */
    public function toggleReviewed(int $uid): ?bool
    {
        return $this->toggleReviewedFlag(self::TABLE, $uid);
    }

    public function deleteRun(string $runLabel): int
    {
        if ($runLabel === '') {
            return 0;
        }

        $queryBuilder = $this->createQueryBuilder(self::TABLE);

        return $queryBuilder->delete(self::TABLE)
            ->where($queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)))
            ->executeStatement();
    }

    private function findUid(string $runLabel, string $environment, string $requestedPath): ?int
    {
        $queryBuilder = $this->createQueryBuilder(self::TABLE);
        $uid = $queryBuilder->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('run_label', $queryBuilder->createNamedParameter($runLabel)),
                $queryBuilder->expr()->eq('environment', $queryBuilder->createNamedParameter($environment)),
                $queryBuilder->expr()->eq('requested_path', $queryBuilder->createNamedParameter($requestedPath)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($uid) ? (int)$uid : null;
    }
}
