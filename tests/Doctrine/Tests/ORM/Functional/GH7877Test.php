<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Tests\OrmFunctionalTestCase;
use function count;

/**
 * @group GH7877
 */
class GH7877Test extends OrmFunctionalTestCase
{
    protected function setUp() : void
    {
        parent::setUp();
        $classMetadatas = [
            $this->_em->getClassMetadata(GH7877ApplicationGenerated::class),
            $this->_em->getClassMetadata(GH7877DatabaseGenerated::class),
        ];
        // We first drop the schema to avoid collision between tests
        $this->_schemaTool->dropSchema($classMetadatas);
        $this->_schemaTool->createSchema($classMetadatas);
    }

    public function providerDifferentEntity()
    {
        yield [GH7877ApplicationGenerated::class];
        yield [GH7877DatabaseGenerated::class];
    }

    /**
     * @dataProvider providerDifferentEntity
     */
    public function testExtraUpdateWithDifferentEntities(string $class)
    {
        $parent = new $class($parentId = 1);
        $this->_em->persist($parent);

        $child         = new $class($childId = 2);
        $child->parent = $parent;
        $this->_em->persist($child);

        $count = count($this->_sqlLoggerStack->queries);
        $this->_em->flush();
        $this->assertCount($count + 5, $this->_sqlLoggerStack->queries);

        $this->_em->clear();

        $child = $this->_em->find($class, $childId);
        $this->assertSame($parentId, $child->parent->id);
    }

    public function testNoExtraUpdateWithApplicationGeneratedId()
    {
        $entity         = new GH7877ApplicationGenerated($entityId = 1);
        $entity->parent = $entity;
        $this->_em->persist($entity);

        $count = count($this->_sqlLoggerStack->queries);
        $this->_em->flush();
        $this->assertCount($count + 3, $this->_sqlLoggerStack->queries);

        $this->_em->clear();

        $child = $this->_em->find(GH7877ApplicationGenerated::class, $entityId);
        $this->assertSame($entityId, $child->parent->id);
    }

    public function textExtraUpdateWithDatabaseGeneratedId()
    {
        $entity         = new GH7877DatabaseGenerated();
        $entity->parent = $entity;
        $this->_em->persist($entity);

        $count = count($this->_sqlLoggerStack->queries);
        $this->_em->flush();
        $this->assertCount($count + 4, $this->_sqlLoggerStack->queries);
        $entityId = $entity->id;

        $this->_em->clear();

        $child = $this->_em->find(GH7877DatabaseGenerated::class, $entityId);
        $this->assertSame($entityId, $child->parent->id);
    }
}

/**
 * @Entity
 */
class GH7877ApplicationGenerated
{
    public function __construct(int $id)
    {
        $this->id = $id;
    }

    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="NONE")
     */
    public $id;

    /** @ManyToOne(targetEntity="Doctrine\Tests\ORM\Functional\GH7877ApplicationGenerated") */
    public $parent;
}

/**
 * @Entity
 */
class GH7877DatabaseGenerated
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /** @ManyToOne(targetEntity="Doctrine\Tests\ORM\Functional\Issue7877DatabaseGenerated") */
    public $parent;
}
