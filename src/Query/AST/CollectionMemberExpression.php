<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * CollectionMemberExpression ::= EntityExpression ["NOT"] "MEMBER" ["OF"] CollectionValuedPathExpression
 *
 * @link    www.doctrine-project.org
 */
class CollectionMemberExpression extends Node
{
    public function __construct(
        public mixed $entityExpression,
        public PathExpression $collectionValuedPathExpression,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkCollectionMemberExpression($this);
    }
}
