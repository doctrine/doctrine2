<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\Models\PropertyHooks\HookedIdentifier;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;

#[RequiresPhp('>= 8.4.0')]
class PropertyHooksIdentifierTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->_em->getConfiguration()->isNativeLazyObjectsEnabled()) {
            $this->markTestSkipped('Property hooks require native lazy objects to be enabled.');
        }

        $this->createSchemaForModels(
            HookedIdentifier::class,
        );
    }

    public function testRemoveEntityWithHookedIdentifier(): void
    {
        $entity = new HookedIdentifier();

        $this->_em->persist($entity);
        $this->_em->flush();

        $id = $entity->id;

        $this->_em->remove($entity);
        $this->_em->flush();

        self::assertNull($this->_em->find(HookedIdentifier::class, $id));
    }
}
