<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Exception\SharedCollection;
use Doctrine\Tests\Models\CustomCollection\SharedTag;
use Doctrine\Tests\Models\CustomCollection\SharedTagLists;
use Doctrine\Tests\OrmFunctionalTestCase;

class CustomCollectionSharedInstanceTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(SharedTag::class, SharedTagLists::class);
    }

    public function testSharingOneCollectionBetweenTwoAssociationsThrows(): void
    {
        $tag    = new SharedTag('php');
        $entity = new SharedTagLists();
        $entity->firstTags->add($tag);

        $this->_em->persist($tag);
        $this->_em->persist($entity);

        $this->expectException(SharedCollection::class);
        $this->expectExceptionMessage('A collection instance cannot be shared between two associations.');

        $this->_em->flush();
    }
}
