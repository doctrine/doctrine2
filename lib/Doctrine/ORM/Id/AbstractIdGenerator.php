<?php

declare(strict_types=1);

namespace Doctrine\ORM\Id;

use Doctrine\ORM\EntityManager;

abstract class AbstractIdGenerator
{
    /**
     * Generates an identifier for an entity.
     *
     * @param object|null $entity
     *
     * @return mixed
     */
    abstract public function generate(EntityManager $em, $entity);

    /**
     * Gets whether this generator is a post-insert generator which means that
     * {@link generate()} must be called after the entity has been inserted
     * into the database.
     *
     * By default, this method returns FALSE. Generators that have this requirement
     * must override this method and return TRUE.
     *
     * @return bool
     */
    public function isPostInsertGenerator()
    {
        return false;
    }
}
