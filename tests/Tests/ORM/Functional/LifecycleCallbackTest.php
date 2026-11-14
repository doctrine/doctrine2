<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\PostLoad;
use Doctrine\ORM\Mapping\PostPersist;
use Doctrine\ORM\Mapping\PostRemove;
use Doctrine\ORM\Mapping\PostUpdate;
use Doctrine\ORM\Mapping\PreFlush;
use Doctrine\ORM\Mapping\PrePersist;
use Doctrine\ORM\Mapping\PreRemove;
use Doctrine\ORM\Mapping\PreUpdate;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

use function count;
use function current;
use function iterator_to_array;
use function sprintf;

class LifecycleCallbackTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(
            LifecycleCallbackEventArgEntity::class,
            LifecycleCallbackTestEntity::class,
            LifecycleCallbackTestUser::class,
            LifecycleCallbackCascader::class,
        );
    }

    public function testPreSavePostSaveCallbacksAreInvoked(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);
        $this->_em->flush();

        self::assertTrue($entity->prePersistCallbackInvoked);
        self::assertTrue($entity->postPersistCallbackInvoked);

        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation

        $query  = $this->_em->createQuery('select e from Doctrine\Tests\ORM\Functional\LifecycleCallbackTestEntity e');
        $result = $query->getResult();
        self::assertTrue($result[0]::$postLoadCallbackInvoked);

        $result[0]->value = 'hello again';

        $this->_em->flush();

        self::assertEquals('changed from preUpdate callback!', $result[0]->value);
    }

    public function testPreFlushCallbacksAreInvoked(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);

        $this->_em->flush();

        self::assertTrue($entity->prePersistCallbackInvoked);
        self::assertTrue($entity->preFlushCallbackInvoked);

        $entity->preFlushCallbackInvoked = false;
        $this->_em->flush();

        self::assertTrue($entity->preFlushCallbackInvoked);

        $entity->value                   = 'bye';
        $entity->preFlushCallbackInvoked = false;
        $this->_em->flush();

        self::assertTrue($entity->preFlushCallbackInvoked);
    }

    public function testChangesDontGetLost(): void
    {
        $user = new LifecycleCallbackTestUser();
        $user->setName('Bob');
        $user->setValue('value');
        $this->_em->persist($user);
        $this->_em->flush();

        $user->setName('Alice');
        $this->_em->flush(); // Triggers preUpdate

        $this->_em->clear();

        $user2 = $this->_em->find($user::class, $user->getId());

        self::assertEquals('Alice', $user2->getName());
        self::assertEquals('Hello World', $user2->getValue());
    }

    #[Group('DDC-194')]
    public function testGetReferenceWithPostLoadEventIsDelayedUntilProxyTrigger(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->getId();

        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation

        $reference = $this->_em->getReference(LifecycleCallbackTestEntity::class, $id);
        self::assertArrayNotHasKey('postLoadCallbackInvoked', (array) $reference);
        $this->assertTrue($this->isUninitializedObject($reference));

        $reference->getValue(); // trigger proxy load
        self::assertTrue($reference::$postLoadCallbackInvoked);
    }

    #[Group('DDC-958')]
    public function testPostLoadTriggeredOnRefresh(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->getId();

        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation

        $reference = $this->_em->find(LifecycleCallbackTestEntity::class, $id);
        self::assertTrue($reference::$postLoadCallbackInvoked);
        $reference::$postLoadCallbackInvoked = false;

        $this->_em->refresh($reference);
        self::assertTrue($reference::$postLoadCallbackInvoked, 'postLoad should be invoked when refresh() is called.');
    }

    #[Group('DDC-113')]
    public function testCascadedEntitiesCallsPrePersist(): void
    {
        $e1 = new LifecycleCallbackTestEntity();
        $e2 = new LifecycleCallbackTestEntity();

        $c = new LifecycleCallbackCascader();
        $this->_em->persist($c);

        $c->entities[] = $e1;
        $c->entities[] = $e2;
        $e1->cascader  = $c;
        $e2->cascader  = $c;

        //$this->_em->persist($c);
        $this->_em->flush();

        self::assertTrue($e1->prePersistCallbackInvoked);
        self::assertTrue($e2->prePersistCallbackInvoked);
    }

    #[Group('DDC-54')]
    #[Group('DDC-3005')]
    public function testCascadedEntitiesLoadedInPostLoad(): void
    {
        $e1 = new LifecycleCallbackTestEntity();
        $e2 = new LifecycleCallbackTestEntity();

        $c = new LifecycleCallbackCascader();
        $this->_em->persist($c);

        $c->entities[] = $e1;
        $c->entities[] = $e2;
        $e1->cascader  = $c;
        $e2->cascader  = $c;

        $this->_em->flush();
        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation

        $dql = <<<'DQL'
SELECT
    e, c
FROM
    Doctrine\Tests\ORM\Functional\LifecycleCallbackTestEntity AS e
LEFT JOIN
    e.cascader AS c
WHERE
    e.id IN (%s, %s)
DQL;

        $entities = $this
            ->_em
            ->createQuery(sprintf($dql, $e1->getId(), $e2->getId()))
            ->getResult();

        self::assertTrue(current($entities)::$postLoadCallbackInvoked);
        self::assertTrue(current($entities)->postLoadCascaderNotNull);
        self::assertTrue(current($entities)->cascader::$postLoadCallbackInvoked);
        self::assertEquals(current($entities)->cascader->postLoadEntitiesCount, 2);
    }

    #[Group('DDC-54')]
    #[Group('DDC-3005')]
    public function testFetchJoinedToOneIsLoadedInPostLoadDuringIteration(): void
    {
        $e1 = new LifecycleCallbackTestEntity();
        $e2 = new LifecycleCallbackTestEntity();

        $c = new LifecycleCallbackCascader();
        $this->_em->persist($c);

        $c->entities[] = $e1;
        $c->entities[] = $e2;
        $e1->cascader  = $c;
        $e2->cascader  = $c;

        $this->_em->flush();
        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation
        LifecycleCallbackCascader::$postLoadCallbackInvoked   = false;

        $dql = <<<'DQL'
SELECT
    e, c
FROM
    Doctrine\Tests\ORM\Functional\LifecycleCallbackTestEntity AS e
LEFT JOIN
    e.cascader AS c
WHERE
    e.id IN (%s, %s)
DQL;

        $query = $this->_em->createQuery(sprintf($dql, $e1->getId(), $e2->getId()));

        $iterableResult = iterator_to_array($query->toIterable());

        foreach ($iterableResult as $entity) {
            self::assertTrue($entity::$postLoadCallbackInvoked);
            self::assertTrue($entity->postLoadCascaderNotNull);

            break;
        }
    }

    #[Group('DDC-54')]
    #[Group('DDC-3005')]
    public function testCascadedEntitiesNotLoadedInPostLoadDuringIterationWithSimpleObjectHydrator(): void
    {
        $this->_em->persist(new LifecycleCallbackTestEntity());
        $this->_em->persist(new LifecycleCallbackTestEntity());

        $this->_em->flush();
        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation

        $query  = $this->_em->createQuery(
            'SELECT e FROM Doctrine\Tests\ORM\Functional\LifecycleCallbackTestEntity AS e',
        );
        $result = iterator_to_array($query->toIterable([], Query::HYDRATE_SIMPLEOBJECT));

        foreach ($result as $entity) {
            self::assertTrue($entity::$postLoadCallbackInvoked);
            self::assertFalse($entity->postLoadCascaderNotNull);

            break;
        }
    }

    /**
     * https://github.com/doctrine/orm/issues/6568
     */
    public function testPostLoadIsInvokedOnFetchJoinedEntities(): void
    {
        $entA = new LifecycleCallbackCascader();
        $this->_em->persist($entA);

        $entB1 = new LifecycleCallbackTestEntity();
        $entB2 = new LifecycleCallbackTestEntity();

        $entA->entities[] = $entB1;
        $entA->entities[] = $entB2;
        $entB1->cascader  = $entA;
        $entB2->cascader  = $entA;

        $this->_em->flush();
        $this->_em->clear();
        LifecycleCallbackTestEntity::$postLoadCallbackInvoked = false; // Reset the tracking of the postLoad invocation
        LifecycleCallbackCascader::$postLoadCallbackInvoked   = false;

        $dql = <<<'DQL'
SELECT
    entA, entB
FROM
    Doctrine\Tests\ORM\Functional\LifecycleCallbackCascader AS entA
LEFT JOIN
    entA.entities AS entB
WHERE
    entA.id = :entA_id
DQL;

        $fetchedA = $this
            ->_em
            ->createQuery($dql)->setParameter('entA_id', $entA->getId())
            ->getOneOrNullResult();

        self::assertTrue($fetchedA::$postLoadCallbackInvoked);
        foreach ($fetchedA->entities as $fetchJoinedEntB) {
            self::assertTrue($fetchJoinedEntB::$postLoadCallbackInvoked);
        }
    }

    public function testLifecycleCallbacksGetInherited(): void
    {
        $childMeta = $this->_em->getClassMetadata(LifecycleCallbackChildEntity::class);
        self::assertEquals(['prePersist' => [0 => 'doStuff']], $childMeta->lifecycleCallbacks);
    }

    public function testLifecycleListenerChangeUpdateChangeSet(): void
    {
        $listener = new LifecycleListenerPreUpdate();
        $this->_em->getEventManager()->addEventListener(['preUpdate'], $listener);

        $user = new LifecycleCallbackTestUser();
        $user->setName('Bob');
        $user->setValue('value');
        $this->_em->persist($user);
        $this->_em->flush();
        $this->_em->clear();

        $dql = "SELECT u FROM Doctrine\Tests\ORM\Functional\LifecycleCallbackTestUser u WHERE u.name = 'Bob'";
        $bob = $this->_em->createQuery($dql)->getSingleResult();
        $bob->setName('Alice');

        $this->_em->flush(); // preUpdate reverts Alice to Bob
        $this->_em->clear();

        $this->_em->getEventManager()->removeEventListener(['preUpdate'], $listener);

        $bob = $this->_em->createQuery($dql)->getSingleResult();

        self::assertEquals('Bob', $bob->getName());
    }

    #[Group('DDC-1955')]
    public function testLifecycleCallbackEventArgs(): void
    {
        $e = new LifecycleCallbackEventArgEntity();

        $e->value = 'foo';
        $this->_em->persist($e);
        $this->_em->flush();

        $e->value = 'var';
        $this->_em->persist($e);
        $this->_em->flush();

        $this->_em->refresh($e);

        $this->_em->remove($e);
        $this->_em->flush();

        self::assertArrayHasKey('preFlushHandler', $e->calls);
        self::assertArrayHasKey('postLoadHandler', $e->calls);
        self::assertArrayHasKey('prePersistHandler', $e->calls);
        self::assertArrayHasKey('postPersistHandler', $e->calls);
        self::assertArrayHasKey('preUpdateHandler', $e->calls);
        self::assertArrayHasKey('postUpdateHandler', $e->calls);
        self::assertArrayHasKey('preRemoveHandler', $e->calls);
        self::assertArrayHasKey('postRemoveHandler', $e->calls);

        self::assertInstanceOf(PreFlushEventArgs::class, $e->calls['preFlushHandler']);
        self::assertInstanceOf(PostLoadEventArgs::class, $e->calls['postLoadHandler']);
        self::assertInstanceOf(PrePersistEventArgs::class, $e->calls['prePersistHandler']);
        self::assertInstanceOf(PostPersistEventArgs::class, $e->calls['postPersistHandler']);
        self::assertInstanceOf(PreUpdateEventArgs::class, $e->calls['preUpdateHandler']);
        self::assertInstanceOf(PostUpdateEventArgs::class, $e->calls['postUpdateHandler']);
        self::assertInstanceOf(PreRemoveEventArgs::class, $e->calls['preRemoveHandler']);
        self::assertInstanceOf(PostRemoveEventArgs::class, $e->calls['postRemoveHandler']);
    }
}

#[Entity]
#[HasLifecycleCallbacks]
class LifecycleCallbackTestUser
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private int $id;

    #[Column(type: 'string', length: 255)]
    private string|null $value = null;

    #[Column(type: 'string', length: 255)]
    private string|null $name = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    #[PreUpdate]
    public function testCallback(): void
    {
        $this->value = 'Hello World';
    }
}

#[Table(name: 'lc_cb_test_entity')]
#[Entity]
#[HasLifecycleCallbacks]
class LifecycleCallbackTestEntity
{
    /* test stuff */

    /** @var bool */
    public $prePersistCallbackInvoked = false;

    /** @var bool */
    public $postPersistCallbackInvoked = false;

    /** @var bool */
    public static $postLoadCallbackInvoked = false;

    /** @var bool */
    public $postLoadCascaderNotNull = false;

    /** @var bool */
    public $preFlushCallbackInvoked = false;

    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    /** @var string */
    #[Column(type: 'string', nullable: true)]
    public $value;

    /** @var LifecycleCallbackCascader */
    #[ManyToOne(targetEntity: 'LifecycleCallbackCascader')]
    #[JoinColumn(name: 'cascader_id', referencedColumnName: 'id')]
    public $cascader;

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    #[PrePersist]
    public function doStuffOnPrePersist(): void
    {
        $this->prePersistCallbackInvoked = true;
    }

    #[PostPersist]
    public function doStuffOnPostPersist(): void
    {
        $this->postPersistCallbackInvoked = true;
    }

    #[PostLoad]
    public function doStuffOnPostLoad(): void
    {
        self::$postLoadCallbackInvoked = true;
        $this->postLoadCascaderNotNull = isset($this->cascader);
    }

    #[PreUpdate]
    public function doStuffOnPreUpdate(): void
    {
        $this->value = 'changed from preUpdate callback!';
    }

    #[PreFlush]
    public function doStuffOnPreFlush(): void
    {
        $this->preFlushCallbackInvoked = true;
    }
}

#[Table(name: 'lc_cb_test_cascade')]
#[Entity]
#[HasLifecycleCallbacks]
class LifecycleCallbackCascader
{
    /* test stuff */
    /** @var bool */
    public static $postLoadCallbackInvoked = false;

    /** @var int */
    public $postLoadEntitiesCount = 0;

    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue(strategy: 'AUTO')]
    private int $id;

    /** @phpstan-var Collection<int, LifecycleCallbackTestEntity> */
    #[OneToMany(targetEntity: 'LifecycleCallbackTestEntity', mappedBy: 'cascader', cascade: ['persist'])]
    public $entities;

    public function __construct()
    {
        $this->entities = new ArrayCollection();
    }

    #[PostLoad]
    public function doStuffOnPostLoad(): void
    {
        self::$postLoadCallbackInvoked = true;
        $this->postLoadEntitiesCount   = count($this->entities);
    }

    public function getId(): int
    {
        return $this->id;
    }
}

#[MappedSuperclass]
#[HasLifecycleCallbacks]
class LifecycleCallbackParentEntity
{
    #[PrePersist]
    public function doStuff(): void
    {
    }
}

#[Table(name: 'lc_cb_childentity')]
#[Entity]
class LifecycleCallbackChildEntity extends LifecycleCallbackParentEntity
{
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    private int $id;
}

class LifecycleListenerPreUpdate
{
    public function preUpdate(PreUpdateEventArgs $eventArgs): void
    {
        $eventArgs->setNewValue('name', 'Bob');
    }
}

#[Entity]
#[HasLifecycleCallbacks]
class LifecycleCallbackEventArgEntity
{
    /** @var int */
    #[Id]
    #[Column(type: 'integer')]
    #[GeneratedValue]
    public $id;

    /** @var string */
    #[Column]
    public $value;

    /** @var array<string, BaseLifecycleEventArgs> */
    public $calls = [];

    #[PostPersist]
    public function postPersistHandler(PostPersistEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PrePersist]
    public function prePersistHandler(PrePersistEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PostUpdate]
    public function postUpdateHandler(PostUpdateEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PreUpdate]
    public function preUpdateHandler(PreUpdateEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PostRemove]
    public function postRemoveHandler(PostRemoveEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PreRemove]
    public function preRemoveHandler(PreRemoveEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PreFlush]
    public function preFlushHandler(PreFlushEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    #[PostLoad]
    public function postLoadHandler(PostLoadEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }
}
