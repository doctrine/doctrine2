<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\QueryException;
use Doctrine\ORM\Utility\LockSqlHelper;
use Override;

/**
 * SingleSelectSqlFinalizer finalizes a given SQL query by applying
 * the query's firstResult/maxResult values as well as extra read lock/write lock
 * statements, both through the platform-specific methods.
 *
 * The resulting, "finalized" SQL is passed to a FinalizedSelectExecutor.
 */
class SingleSelectSqlFinalizer implements SqlFinalizer
{
    use LockSqlHelper;

    public function __construct(private string $sql)
    {
    }

    /**
     * This method exists temporarily to support old SqlWalker interfaces.
     *
     * @internal
     */
    public function finalizeSql(Query $query): string
    {
        $platform = $query->getEntityManager()->getConnection()->getDatabasePlatform();

        $sql = $platform->modifyLimitQuery($this->sql, $query->getMaxResults(), $query->getFirstResult());

        $lockMode = $query->getHint(Query::HINT_LOCK_MODE) ?: LockMode::NONE;

        if ($lockMode !== LockMode::NONE && $lockMode !== LockMode::OPTIMISTIC && $lockMode !== LockMode::PESSIMISTIC_READ && $lockMode !== LockMode::PESSIMISTIC_WRITE) {
            throw QueryException::invalidLockMode();
        }

        if ($lockMode === LockMode::PESSIMISTIC_READ) {
            $sql .= ' ' . $this->getReadLockSQL($platform);
        } elseif ($lockMode === LockMode::PESSIMISTIC_WRITE) {
            $sql .= ' ' . $this->getWriteLockSQL($platform);
        }

        return $sql;
    }

    /** @return FinalizedSelectExecutor */
    #[Override]
    public function createExecutor(Query $query): AbstractSqlExecutor
    {
        return new FinalizedSelectExecutor($this->finalizeSql($query));
    }
}
