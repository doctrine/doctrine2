<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\Deprecations\Deprecation;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Executor that executes the SQL statement for simple DQL SELECT statements.
 *
 * @deprecated This class is no longer needed by the ORM and will be removed in 4.0.
 *
 * @link        www.doctrine-project.org
 */
class SingleSelectExecutor extends AbstractSqlExecutor
{
    public function __construct(SelectStatement $AST, SqlWalker $sqlWalker)
    {
        Deprecation::trigger(
            'doctrine/orm',
            'https://github.com/doctrine/orm/pull/11188/',
            'The %s is no longer needed by the ORM and will be removed in 4.0',
            self::class,
        );

        $this->sqlStatements = $sqlWalker->walkSqlComments() . $sqlWalker->walkSelectStatement($AST);
    }

    /**
     * {@inheritDoc}
     */
    public function execute(Connection $conn, array $params, array $types): Result
    {
        return $conn->executeQuery($this->sqlStatements, $params, $types, $this->queryCacheProfile);
    }
}
