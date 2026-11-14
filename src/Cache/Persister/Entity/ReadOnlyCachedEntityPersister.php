<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\Exception\CannotUpdateReadOnlyEntity;
use Override;

/**
 * Specific read-only region entity persister
 */
class ReadOnlyCachedEntityPersister extends NonStrictReadWriteCachedEntityPersister
{
    #[Override]
    public function update(object $entity): void
    {
        throw CannotUpdateReadOnlyEntity::fromEntity($entity::class);
    }
}
