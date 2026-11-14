<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache\Persister\Collection;

use Doctrine\ORM\Cache\Exception\CannotUpdateReadOnlyCollection;
use Doctrine\ORM\PersistentCollection;
use Override;

class ReadOnlyCachedCollectionPersister extends NonStrictReadWriteCachedCollectionPersister
{
    #[Override]
    public function update(PersistentCollection $collection): void
    {
        if ($collection->isDirty() && $collection->getSnapshot()) {
            throw CannotUpdateReadOnlyCollection::fromEntityAndField(
                $collection->getOwner()::class,
                $this->association->fieldName,
            );
        }

        parent::update($collection);
    }
}
