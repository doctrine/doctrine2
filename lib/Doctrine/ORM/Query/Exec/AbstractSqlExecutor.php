<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Type;

/**
 * Base class for SQL statement executors.
 *
 * @link        http://www.doctrine-project.org
 *
 * @todo Rename: AbstractSQLExecutor
 */
abstract class AbstractSqlExecutor
{
    /** @var mixed[]|string */
    protected $_sqlStatements;

    /** @var QueryCacheProfile */
    protected $queryCacheProfile;

    /**
     * Gets the SQL statements that are executed by the executor.
     *
     * @return mixed[]|string  All the SQL update statements.
     */
    public function getSqlStatements()
    {
        return $this->_sqlStatements;
    }

    /**
     * @return void
     */
    public function setQueryCacheProfile(QueryCacheProfile $qcp)
    {
        $this->queryCacheProfile = $qcp;
    }

    /**
     * Do not use query cache
     *
     * @return void
     */
    public function removeQueryCacheProfile()
    {
        $this->queryCacheProfile = null;
    }

    /**
     * Executes all sql statements.
     *
     * @param Connection $conn The database connection that is used to execute the queries.
     * @psalm-param array<int, mixed>|array<string, mixed> $params The parameters.
     * @psalm-param array<int, int|string|Type|null>|
     *              array<string, int|string|Type|null> $types The parameter types.
     *
     * @return Result|int
     */
    abstract public function execute(Connection $conn, array $params, array $types);
}
