<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

class Literal extends Node
{
    final public const int STRING  = 1;
    final public const int BOOLEAN = 2;
    final public const int NUMERIC = 3;
    final public const int NULL    = 4;

    /** @phpstan-param self::* $type */
    public function __construct(
        public int $type,
        public mixed $value,
    ) {
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkLiteral($this);
    }
}
