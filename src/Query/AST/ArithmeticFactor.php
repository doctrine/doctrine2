<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\SqlWalker;
use Override;

/**
 * ArithmeticFactor ::= [("+" | "-")] ArithmeticPrimary
 *
 * @link    www.doctrine-project.org
 */
class ArithmeticFactor extends Node
{
    public function __construct(
        public mixed $arithmeticPrimary,
        public bool|null $sign = null,
    ) {
    }

    public function isPositiveSigned(): bool
    {
        return $this->sign === true;
    }

    public function isNegativeSigned(): bool
    {
        return $this->sign === false;
    }

    #[Override]
    public function dispatch(SqlWalker $walker): string
    {
        return $walker->walkArithmeticFactor($this);
    }
}
