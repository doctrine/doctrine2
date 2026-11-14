<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * LikeExpression ::= StringExpression ["NOT"] "LIKE" string ["ESCAPE" char]
 *
 * @link    www.doctrine-project.org
 */
class LikeExpression extends Node
{
    public function __construct(
        public Node|string $stringExpression,
        public InputParameter|FunctionNode|PathExpression|Literal $stringPattern,
        public Literal|null $escapeChar = null,
        public bool $not = false,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkLikeExpression($this);
    }
}
