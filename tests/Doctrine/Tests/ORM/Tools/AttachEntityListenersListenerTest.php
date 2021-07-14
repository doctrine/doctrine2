<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Tools\AttachEntityListenersListener;
use Doctrine\Tests\OrmTestCase;

use function func_get_args;

class AttachEntityListenersListenerTest extends OrmTestCase
{
    /** @var EntityManager */
    private $em;

    /** @var AttachEntityListenersListener */
    private $listener;

    /** @var ClassMetadataFactory */
    private $factory;

    protected function setUp(): void
    {
        $this->listener = new AttachEntityListenersListener();
        $driver         = $this->createAnnotationDriver();
        $this->em       = $this->getTestEntityManager();
        $evm            = $this->em->getEventManager();
        $this->factory  = new ClassMetadataFactory();

        $evm->addEventListener(Events::loadClassMetadata, $this->listener);
        $this->em->getConfiguration()->setMetadataDriverImpl($driver);
        $this->factory->setEntityManager($this->em);
    }

    public function testAttachEntityListeners(): void
    {
        $this->listener->addEntityListener(
            AttachEntityListenersListenerTestFooEntity::class,
            AttachEntityListenersListenerTestListener::class,
            Events::postLoad,
            'postLoadHandler'
        );

        $metadata = $this->factory->getMetadataFor(AttachEntityListenersListenerTestFooEntity::class);

        $this->assertArrayHasKey('postLoad', $metadata->entityListeners);
        $this->assertCount(1, $metadata->entityListeners['postLoad']);
        $this->assertEquals('postLoadHandler', $metadata->entityListeners['postLoad'][0]['method']);
        $this->assertEquals(AttachEntityListenersListenerTestListener::class, $metadata->entityListeners['postLoad'][0]['class']);
    }

    public function testAttachToExistingEntityListeners(): void
    {
        $this->listener->addEntityListener(
            AttachEntityListenersListenerTestBarEntity::class,
            AttachEntityListenersListenerTestListener2::class,
            Events::prePersist
        );

        $this->listener->addEntityListener(
            AttachEntityListenersListenerTestBarEntity::class,
            AttachEntityListenersListenerTestListener2::class,
            Events::postPersist,
            'postPersistHandler'
        );

        $metadata = $this->factory->getMetadataFor(AttachEntityListenersListenerTestBarEntity::class);

        $this->assertArrayHasKey('postPersist', $metadata->entityListeners);
        $this->assertArrayHasKey('prePersist', $metadata->entityListeners);

        $this->assertCount(2, $metadata->entityListeners['prePersist']);
        $this->assertCount(2, $metadata->entityListeners['postPersist']);

        $this->assertEquals('prePersist', $metadata->entityListeners['prePersist'][0]['method']);
        $this->assertEquals(AttachEntityListenersListenerTestListener::class, $metadata->entityListeners['prePersist'][0]['class']);

        $this->assertEquals('prePersist', $metadata->entityListeners['prePersist'][1]['method']);
        $this->assertEquals(AttachEntityListenersListenerTestListener2::class, $metadata->entityListeners['prePersist'][1]['class']);

        $this->assertEquals('postPersist', $metadata->entityListeners['postPersist'][0]['method']);
        $this->assertEquals(AttachEntityListenersListenerTestListener::class, $metadata->entityListeners['postPersist'][0]['class']);

        $this->assertEquals('postPersistHandler', $metadata->entityListeners['postPersist'][1]['method']);
        $this->assertEquals(AttachEntityListenersListenerTestListener2::class, $metadata->entityListeners['postPersist'][1]['class']);
    }

    public function testDuplicateEntityListenerException(): void
    {
        $this->expectException('Doctrine\ORM\Mapping\MappingException');
        $this->expectExceptionMessage('Entity Listener "Doctrine\Tests\ORM\Tools\AttachEntityListenersListenerTestListener#postPersist()" in "Doctrine\Tests\ORM\Tools\AttachEntityListenersListenerTestFooEntity" was already declared, but it must be declared only once.');
        $this->listener->addEntityListener(
            AttachEntityListenersListenerTestFooEntity::class,
            AttachEntityListenersListenerTestListener::class,
            Events::postPersist
        );

        $this->listener->addEntityListener(
            AttachEntityListenersListenerTestFooEntity::class,
            AttachEntityListenersListenerTestListener::class,
            Events::postPersist
        );

        $this->factory->getMetadataFor(AttachEntityListenersListenerTestFooEntity::class);
    }
}

/**
 * @Entity
 */
class AttachEntityListenersListenerTestFooEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}

/**
 * @Entity
 * @EntityListeners({"AttachEntityListenersListenerTestListener"})
 */
class AttachEntityListenersListenerTestBarEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}

class AttachEntityListenersListenerTestListener
{
    /** @psalm-var array<string,list<list<mixed>>> */
    public $calls;

    public function prePersist(): void
    {
        $this->calls[__FUNCTION__][] = func_get_args();
    }

    public function postLoadHandler(): void
    {
        $this->calls[__FUNCTION__][] = func_get_args();
    }

    public function postPersist(): void
    {
        $this->calls[__FUNCTION__][] = func_get_args();
    }
}

class AttachEntityListenersListenerTestListener2
{
    /** @psalm-var array<string,list<list<mixed>>> */
    public $calls;

    public function prePersist(): void
    {
        $this->calls[__FUNCTION__][] = func_get_args();
    }

    public function postPersistHandler(): void
    {
        $this->calls[__FUNCTION__][] = func_get_args();
    }
}
