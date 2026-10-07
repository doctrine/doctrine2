<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\UnitOfWork;

use function array_values;
use function spl_object_id;

/**
 * Result of constraint-edge planning.
 *
 * @internal
 */
final class ConstraintEdgePlan
{
    /**
     * @param list<ConstraintEdge> $edges            resolvable DELETE-before-INSERT edges
     * @param list<object>         $blockedDeletions colliding candidates not safe to run early
     */
    public function __construct(
        public readonly array $edges,
        public readonly array $blockedDeletions,
    ) {
    }

    /**
     * Distinct deletion sides of the resolvable edges.
     *
     * @return list<object>
     */
    public function earlyDeletions(): array
    {
        $deletions = [];

        foreach ($this->edges as $edge) {
            $deletions[spl_object_id($edge->deletion)] = $edge->deletion;
        }

        return array_values($deletions);
    }
}
