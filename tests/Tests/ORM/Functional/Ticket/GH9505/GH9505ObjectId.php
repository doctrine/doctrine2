<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH9505;

use Stringable;

/**
 * Minimal stand-in for a real-world Stringable identifier value object (e.g.
 * Symfony\Component\Uid\Uuid) — deliberately has no __equals()/comparison method, only
 * __toString(), same as Uuid. convertToPHPValue() always builds a fresh instance (see
 * GH9505ObjectIdType), so two instances holding the same $value are never === to each other.
 */
final class GH9505ObjectId implements Stringable
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
