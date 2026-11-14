<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * EmptyCollectionComparisonExpression ::= CollectionValuedPathExpression "IS" ["NOT"] "EMPTY"
 *
 * @link    www.doctrine-project.org
 */
class EmptyCollectionComparisonExpression extends Node
{
    public function __construct(
        public PathExpression $expression,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkEmptyCollectionComparisonExpression($this);
    }
}
