<?php

declare(strict_types=1);

namespace Doctrine\ORM\Internal\UnitOfWork;

/**
 * A DELETE-before-INSERT ordering constraint between a pending deletion and a
 * pending insertion that collide on a metadata-declared unique tuple.
 *
 * @internal
 */
final class ConstraintEdge
{
    public function __construct(
        public readonly object $deletion,
        public readonly object $insertion,
    ) {
    }
}
