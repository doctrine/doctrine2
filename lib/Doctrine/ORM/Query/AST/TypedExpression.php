<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\DBAL\Types\Type;

/**
 * Provides an API for resolving the type of a Node
 */
interface TypedExpression
{
    public function getReturnType(): Type;
}
