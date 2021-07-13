<?php

declare(strict_types=1);

namespace Doctrine\ORM\Query\AST;

use Doctrine\ORM\Query\QueryException;

use function get_class;

/**
 * Base exception class for AST exceptions.
 */
class ASTException extends QueryException
{
    /**
     * @param Node $node
     *
     * @return ASTException
     */
    public static function noDispatchForNode($node)
    {
        return new self('Double-dispatch for node ' . get_class($node) . ' is not supported.');
    }
}
