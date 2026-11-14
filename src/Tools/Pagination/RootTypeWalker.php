<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Query\AST;
use Doctrine\ORM\Query\Exec\FinalizedSelectExecutor;
use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\SqlOutputWalker;
use Doctrine\ORM\Utility\PersisterHelper;
use Override;
use RuntimeException;

use function count;
use function reset;

/**
 * Infers the DBAL type of the #Id (identifier) column of the given query's root entity, and
 * returns it in place of a real SQL statement.
 *
 * Obtaining this type is a necessary intermediate step for \Doctrine\ORM\Tools\Pagination\OffsetPaginator
 * and \Doctrine\ORM\Tools\Pagination\CursorPaginator.
 * We can best do this from a tree walker because it gives us access to the AST.
 *
 * Returning the type instead of a "real" SQL statement is a slight hack. However, it has the
 * benefit that the DQL -> root entity id type resolution can be cached in the query cache.
 */
final class RootTypeWalker extends SqlOutputWalker
{
    #[Override]
    public function walkSelectStatement(AST\SelectStatement $selectStatement): string
    {
        // Get the root entity and alias from the AST fromClause
        $from = $selectStatement->fromClause->identificationVariableDeclarations;

        if (count($from) > 1) {
            throw new RuntimeException('Can only process queries that select only one FROM component');
        }

        $fromRoot            = reset($from);
        $rootAlias           = $fromRoot->rangeVariableDeclaration->aliasIdentificationVariable;
        $rootClass           = $this->getMetadataForDqlAlias($rootAlias);
        $identifierFieldName = $rootClass->getSingleIdentifierFieldName();

        return PersisterHelper::getTypeOfField(
            $identifierFieldName,
            $rootClass,
            $this->getQuery()
                ->getEntityManager(),
        )[0];
    }

    #[Override]
    public function getFinalizer(AST\DeleteStatement|AST\UpdateStatement|AST\SelectStatement $AST): SqlFinalizer
    {
        if (! $AST instanceof AST\SelectStatement) {
            throw new RuntimeException(self::class . ' is to be used on SelectStatements only');
        }

        return new PreparedExecutorFinalizer(new FinalizedSelectExecutor($this->walkSelectStatement($AST)));
    }
}
