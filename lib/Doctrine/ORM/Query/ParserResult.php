<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query;

use Doctrine\ORM\Query\Exec\AbstractSqlExecutor;

/**
 * Encapsulates the resulting components from a DQL query parsing process that
 * can be serialized.
 *
 * @link        http://www.doctrine-project.org
 */
class ParserResult
{
    /**
     * The SQL executor used for executing the SQL.
     *
     * @var AbstractSqlExecutor
     */
    private $_sqlExecutor;

    /**
     * The ResultSetMapping that describes how to map the SQL result set.
     *
     * @var ResultSetMapping
     */
    private $_resultSetMapping;

    /**
     * The mappings of DQL parameter names/positions to SQL parameter positions.
     *
     * @psalm-var array<string|int, list<int>>
     */
    private $_parameterMappings = [];

    /**
     * Initializes a new instance of the <tt>ParserResult</tt> class.
     * The new instance is initialized with an empty <tt>ResultSetMapping</tt>.
     */
    public function __construct()
    {
        $this->_resultSetMapping = new ResultSetMapping();
    }

    /**
     * Gets the ResultSetMapping for the parsed query.
     *
     * @return ResultSetMapping The result set mapping of the parsed query
     */
    public function getResultSetMapping()
    {
        return $this->_resultSetMapping;
    }

    /**
     * Sets the ResultSetMapping of the parsed query.
     *
     * @return void
     */
    public function setResultSetMapping(ResultSetMapping $rsm)
    {
        $this->_resultSetMapping = $rsm;
    }

    /**
     * Sets the SQL executor that should be used for this ParserResult.
     *
     * @param AbstractSqlExecutor $executor
     *
     * @return void
     */
    public function setSqlExecutor($executor)
    {
        $this->_sqlExecutor = $executor;
    }

    /**
     * Gets the SQL executor used by this ParserResult.
     *
     * @return AbstractSqlExecutor
     */
    public function getSqlExecutor()
    {
        return $this->_sqlExecutor;
    }

    /**
     * Adds a DQL to SQL parameter mapping. One DQL parameter name/position can map to
     * several SQL parameter positions.
     *
     * @param string|int $dqlPosition
     * @param int        $sqlPosition
     *
     * @return void
     */
    public function addParameterMapping($dqlPosition, $sqlPosition)
    {
        $this->_parameterMappings[$dqlPosition][] = $sqlPosition;
    }

    /**
     * Gets all DQL to SQL parameter mappings.
     *
     * @psalm-return array<int|string, list<int>> The parameter mappings.
     */
    public function getParameterMappings()
    {
        return $this->_parameterMappings;
    }

    /**
     * Gets the SQL parameter positions for a DQL parameter name/position.
     *
     * @param string|int $dqlPosition The name or position of the DQL parameter.
     *
     * @return int[] The positions of the corresponding SQL parameters.
     * @psalm-return list<int>
     */
    public function getSqlParameterPositions($dqlPosition)
    {
        return $this->_parameterMappings[$dqlPosition];
    }
}
