<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools\Pagination;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\PathExpression;

/** @internal */
final class CursorOrderByItem
{
    /**
     * @param ClassMetadata<object>|null $metadata        The metadata of the alias the expression is on.
     * @param list<string>|null          $associationPath The single-valued associations to follow from the
     *                                                    root entity to the alias the expression is on, or
     *                                                    null if that alias cannot be reached that way.
     */
    public function __construct(
        public readonly PathExpression|string $expression,
        public readonly OrderDirection $direction,
        public readonly string $paramKey,
        public readonly ClassMetadata|null $metadata = null,
        public readonly array|null $associationPath = [],
    ) {
    }
}
