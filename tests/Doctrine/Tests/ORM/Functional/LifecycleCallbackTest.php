<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
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
use Exception;

use function count;
use function current;
use function get_class;
use function sprintf;

class LifecycleCallbackTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(LifecycleCallbackEventArgEntity::class),
                    $this->_em->getClassMetadata(LifecycleCallbackTestEntity::class),
                    $this->_em->getClassMetadata(LifecycleCallbackTestUser::class),
                    $this->_em->getClassMetadata(LifecycleCallbackCascader::class),
                ]
            );
        } catch (Exception $e) {
            // Swallow all exceptions. We do not test the schema tool here.
        }
    }

    public function testPreSavePostSaveCallbacksAreInvoked(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);
        $this->_em->flush();

        $this->assertTrue($entity->prePersistCallbackInvoked);
        $this->assertTrue($entity->postPersistCallbackInvoked);

        $this->_em->clear();

        $query  = $this->_em->createQuery('select e from Doctrine\Tests\ORM\Functional\LifecycleCallbackTestEntity e');
        $result = $query->getResult();
        $this->assertTrue($result[0]->postLoadCallbackInvoked);

        $result[0]->value = 'hello again';

        $this->_em->flush();

        $this->assertEquals('changed from preUpdate callback!', $result[0]->value);
    }

    public function testPreFlushCallbacksAreInvoked(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);

        $this->_em->flush();

        $this->assertTrue($entity->prePersistCallbackInvoked);
        $this->assertTrue($entity->preFlushCallbackInvoked);

        $entity->preFlushCallbackInvoked = false;
        $this->_em->flush();

        $this->assertTrue($entity->preFlushCallbackInvoked);

        $entity->value                   = 'bye';
        $entity->preFlushCallbackInvoked = false;
        $this->_em->flush();

        $this->assertTrue($entity->preFlushCallbackInvoked);
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

        $user2 = $this->_em->find(get_class($user), $user->getId());

        $this->assertEquals('Alice', $user2->getName());
        $this->assertEquals('Hello World', $user2->getValue());
    }

    /**
     * @group DDC-194
     */
    public function testGetReferenceWithPostLoadEventIsDelayedUntilProxyTrigger(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->getId();

        $this->_em->clear();

        $reference = $this->_em->getReference(LifecycleCallbackTestEntity::class, $id);
        $this->assertFalse($reference->postLoadCallbackInvoked);

        $reference->getValue(); // trigger proxy load
        $this->assertTrue($reference->postLoadCallbackInvoked);
    }

    /**
     * @group DDC-958
     */
    public function testPostLoadTriggeredOnRefresh(): void
    {
        $entity        = new LifecycleCallbackTestEntity();
        $entity->value = 'hello';
        $this->_em->persist($entity);
        $this->_em->flush();
        $id = $entity->getId();

        $this->_em->clear();

        $reference = $this->_em->find(LifecycleCallbackTestEntity::class, $id);
        $this->assertTrue($reference->postLoadCallbackInvoked);
        $reference->postLoadCallbackInvoked = false;

        $this->_em->refresh($reference);
        $this->assertTrue($reference->postLoadCallbackInvoked, 'postLoad should be invoked when refresh() is called.');
    }

    /**
     * @group DDC-113
     */
    public function testCascadedEntitiesCallsPrePersist(): void
    {
        //$this->_em->getConnection()->getConfiguration()->setSQLLogger(new \Doctrine\DBAL\Logging\EchoSQLLogger);

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

        $this->assertTrue($e1->prePersistCallbackInvoked);
        $this->assertTrue($e2->prePersistCallbackInvoked);
    }

    /**
     * @group DDC-54
     * @group DDC-3005
     */
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

        $this->assertTrue(current($entities)->postLoadCallbackInvoked);
        $this->assertTrue(current($entities)->postLoadCascaderNotNull);
        $this->assertTrue(current($entities)->cascader->postLoadCallbackInvoked);
        $this->assertEquals(current($entities)->cascader->postLoadEntitiesCount, 2);
    }

    /**
     * @group DDC-54
     * @group DDC-3005
     */
    public function testCascadedEntitiesNotLoadedInPostLoadDuringIteration(): void
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

        $result = $query->iterate();

        foreach ($result as $entity) {
            $this->assertTrue($entity[0]->postLoadCallbackInvoked);
            $this->assertFalse($entity[0]->postLoadCascaderNotNull);

            break;
        }

        $iterableResult = $query->toIterable();

        foreach ($iterableResult as $entity) {
            self::assertTrue($entity->postLoadCallbackInvoked);
            self::assertFalse($entity->postLoadCascaderNotNull);

            break;
        }
    }

    /**
     * @group DDC-54
     * @group DDC-3005
     */
    public function testCascadedEntitiesNotLoadedInPostLoadDuringIterationWithSimpleObjectHydrator(): void
    {
        $this->_em->persist(new LifecycleCallbackTestEntity());
        $this->_em->persist(new LifecycleCallbackTestEntity());

        $this->_em->flush();
        $this->_em->clear();

        $query = $this->_em->createQuery(
            'SELECT e FROM Doctrine\Tests\ORM\Functional\LifecycleCallbackTestEntity AS e'
        );

        $result = $query->iterate(null, Query::HYDRATE_SIMPLEOBJECT);

        foreach ($result as $entity) {
            $this->assertTrue($entity[0]->postLoadCallbackInvoked);
            $this->assertFalse($entity[0]->postLoadCascaderNotNull);

            break;
        }

        $result = $query->toIterable([], Query::HYDRATE_SIMPLEOBJECT);

        foreach ($result as $entity) {
            self::assertTrue($entity->postLoadCallbackInvoked);
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

        $this->assertTrue($fetchedA->postLoadCallbackInvoked);
        foreach ($fetchedA->entities as $fetchJoinedEntB) {
            $this->assertTrue($fetchJoinedEntB->postLoadCallbackInvoked);
        }
    }

    public function testLifecycleCallbacksGetInherited(): void
    {
        $childMeta = $this->_em->getClassMetadata(LifecycleCallbackChildEntity::class);
        $this->assertEquals(['prePersist' => [0 => 'doStuff']], $childMeta->lifecycleCallbacks);
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

        $this->assertEquals('Bob', $bob->getName());
    }

    /**
     * @group DDC-1955
     */
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

        $this->assertArrayHasKey('preFlushHandler', $e->calls);
        $this->assertArrayHasKey('postLoadHandler', $e->calls);
        $this->assertArrayHasKey('prePersistHandler', $e->calls);
        $this->assertArrayHasKey('postPersistHandler', $e->calls);
        $this->assertArrayHasKey('preUpdateHandler', $e->calls);
        $this->assertArrayHasKey('postUpdateHandler', $e->calls);
        $this->assertArrayHasKey('preRemoveHandler', $e->calls);
        $this->assertArrayHasKey('postRemoveHandler', $e->calls);

        $this->assertInstanceOf(PreFlushEventArgs::class, $e->calls['preFlushHandler']);
        $this->assertInstanceOf(LifecycleEventArgs::class, $e->calls['postLoadHandler']);
        $this->assertInstanceOf(LifecycleEventArgs::class, $e->calls['prePersistHandler']);
        $this->assertInstanceOf(LifecycleEventArgs::class, $e->calls['postPersistHandler']);
        $this->assertInstanceOf(PreUpdateEventArgs::class, $e->calls['preUpdateHandler']);
        $this->assertInstanceOf(LifecycleEventArgs::class, $e->calls['postUpdateHandler']);
        $this->assertInstanceOf(LifecycleEventArgs::class, $e->calls['preRemoveHandler']);
        $this->assertInstanceOf(LifecycleEventArgs::class, $e->calls['postRemoveHandler']);
    }
}

/** @Entity @HasLifecycleCallbacks */
class LifecycleCallbackTestUser
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
     */
    private $value;

    /**
     * @var string
     * @Column(type="string")
     */
    private $name;

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

    /** @PreUpdate */
    public function testCallback(): void
    {
        $this->value = 'Hello World';
    }
}

/**
 * @Entity
 * @HasLifecycleCallbacks
 * @Table(name="lc_cb_test_entity")
 */
class LifecycleCallbackTestEntity
{
    /* test stuff */

    /** @var bool */
    public $prePersistCallbackInvoked = false;

    /** @var bool */
    public $postPersistCallbackInvoked = false;

    /** @var bool */
    public $postLoadCallbackInvoked = false;

    /** @var bool */
    public $postLoadCascaderNotNull = false;

    /** @var bool */
    public $preFlushCallbackInvoked = false;

    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", nullable=true)
     */
    public $value;

    /**
     * @var LifecycleCallbackCascader
     * @ManyToOne(targetEntity="LifecycleCallbackCascader")
     * @JoinColumn(name="cascader_id", referencedColumnName="id")
     */
    public $cascader;

    public function getId(): int
    {
        return $this->id;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    /** @PrePersist */
    public function doStuffOnPrePersist(): void
    {
        $this->prePersistCallbackInvoked = true;
    }

    /** @PostPersist */
    public function doStuffOnPostPersist(): void
    {
        $this->postPersistCallbackInvoked = true;
    }

    /** @PostLoad */
    public function doStuffOnPostLoad(): void
    {
        $this->postLoadCallbackInvoked = true;
        $this->postLoadCascaderNotNull = isset($this->cascader);
    }

    /** @PreUpdate */
    public function doStuffOnPreUpdate(): void
    {
        $this->value = 'changed from preUpdate callback!';
    }

    /** @PreFlush */
    public function doStuffOnPreFlush(): void
    {
        $this->preFlushCallbackInvoked = true;
    }
}

/**
 * @Entity @HasLifecycleCallbacks
 * @Table(name="lc_cb_test_cascade")
 */
class LifecycleCallbackCascader
{
    /* test stuff */
    /** @var bool */
    public $postLoadCallbackInvoked = false;

    /** @var int */
    public $postLoadEntitiesCount = 0;

    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @psalm-var Collection<int, LifecycleCallbackTestEntity>
     * @OneToMany(targetEntity="LifecycleCallbackTestEntity", mappedBy="cascader", cascade={"persist"})
     */
    public $entities;

    public function __construct()
    {
        $this->entities = new ArrayCollection();
    }

    /** @PostLoad */
    public function doStuffOnPostLoad(): void
    {
        $this->postLoadCallbackInvoked = true;
        $this->postLoadEntitiesCount   = count($this->entities);
    }

    public function getId(): int
    {
        return $this->id;
    }
}

/** @MappedSuperclass @HasLifecycleCallbacks */
class LifecycleCallbackParentEntity
{
    /** @PrePersist */
    public function doStuff(): void
    {
    }
}

/** @Entity @Table(name="lc_cb_childentity") */
class LifecycleCallbackChildEntity extends LifecycleCallbackParentEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;
}

class LifecycleListenerPreUpdate
{
    public function preUpdate(PreUpdateEventArgs $eventArgs): void
    {
        $eventArgs->setNewValue('name', 'Bob');
    }
}

/** @Entity @HasLifecycleCallbacks */
class LifecycleCallbackEventArgEntity
{
    /**
     * @var int
     * @Id @Column(type="integer") @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column()
     */
    public $value;

    /** @var array<string, BaseLifecycleEventArgs> */
    public $calls = [];

    /**
     * @PostPersist
     */
    public function postPersistHandler(LifecycleEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PrePersist
     */
    public function prePersistHandler(LifecycleEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PostUpdate
     */
    public function postUpdateHandler(LifecycleEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PreUpdate
     */
    public function preUpdateHandler(PreUpdateEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PostRemove
     */
    public function postRemoveHandler(LifecycleEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PreRemove
     */
    public function preRemoveHandler(LifecycleEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PreFlush
     */
    public function preFlushHandler(PreFlushEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }

    /**
     * @PostLoad
     */
    public function postLoadHandler(LifecycleEventArgs $event): void
    {
        $this->calls[__FUNCTION__] = $event;
    }
}
