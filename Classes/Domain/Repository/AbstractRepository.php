<?php

declare(strict_types=1);

namespace OliverThiele\OtWebsitecheck\Domain\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

abstract class AbstractRepository
{
    public function __construct(
        protected readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * The tables of this extension are tool data on the root level without
     * enable fields or soft delete, so no restriction applies.
     */
    protected function createQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    /**
     * @return bool|null The new "reviewed" state, or null if the record does not exist.
     */
    protected function toggleReviewedFlag(string $table, int $uid): ?bool
    {
        $queryBuilder = $this->createQueryBuilder($table);
        $currentValue = $queryBuilder->select('reviewed')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne();
        if ($currentValue === false) {
            return null;
        }

        $newValue = is_numeric($currentValue) && (int)$currentValue !== 0 ? 0 : 1;
        $updateQueryBuilder = $this->createQueryBuilder($table);
        $updateQueryBuilder->update($table)
            ->where($updateQueryBuilder->expr()->eq('uid', $updateQueryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->set('reviewed', $newValue, true, ParameterType::INTEGER)
            ->executeStatement();

        return $newValue === 1;
    }
}
