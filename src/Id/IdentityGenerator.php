<?php

declare(strict_types=1);

namespace Doctrine\ORM\Id;

use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * Id generator that obtains IDs from special "identity" columns. These are columns
 * that automatically get a database-generated, auto-incremented identifier on INSERT.
 * This generator obtains the last insert id after such an insert.
 */
class IdentityGenerator extends AbstractIdGenerator
{
    #[Override]
    public function generateId(EntityManagerInterface $em, object|null $entity): int
    {
        return (int) $em->getConnection()->lastInsertId();
    }

    #[Override]
    public function isPostInsertGenerator(): bool
    {
        return true;
    }
}
