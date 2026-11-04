<?php

declare(strict_types=1);

namespace Doctrine\ORM\Id;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Generates the identifier of an entity, before or after it is inserted.
 */
abstract class AbstractIdGenerator
{
    /**
     * Generates an identifier for an entity.
     */
    abstract public function generateId(EntityManagerInterface $em, object|null $entity): mixed;

    /**
     * Gets whether this generator is a post-insert generator which means that
     * {@link generateId()} must be called after the entity has been inserted
     * into the database.
     *
     * By default, this method returns FALSE. Generators that have this requirement
     * must override this method and return TRUE.
     */
    public function isPostInsertGenerator(): bool
    {
        return false;
    }
}
