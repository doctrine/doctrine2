<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Cache\Persister\Entity;

use Doctrine\ORM\Cache\Persister\Entity\ReadOnlyCachedEntityPersister;
use Doctrine\ORM\Cache\Region;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\Tests\Models\Cache\Country;

/**
 * @group DDC-2183
 */
class ReadOnlyCachedEntityPersisterTest extends AbstractEntityPersisterTest
{
    /**
     * {@inheritdoc}
     */
    protected function createPersister(EntityManagerInterface $em, EntityPersister $persister, Region $region, ClassMetadata $metadata)
    {
        return new ReadOnlyCachedEntityPersister($persister, $region, $em, $metadata);
    }

    /**
     * @expectedException Doctrine\ORM\Cache\CacheException
     * @expectedExceptionMessage Cannot update a readonly entity "Doctrine\Tests\Models\Cache\Country"
     */
    public function testInvokeUpdate() : void
    {
        $persister = $this->createPersisterDefault();
        $entity    = new Country('Foo');

        $persister->update($entity);
    }
}
