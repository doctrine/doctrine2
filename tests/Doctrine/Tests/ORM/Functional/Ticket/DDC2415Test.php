<?php

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AbstractIdGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\StaticPHPDriver;

/**
 * @group DDC-2415
 */
class DDC2415Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        $this->_em->getConfiguration()->setMetadataDriverImpl(new StaticPHPDriver([]));

        $this->_schemaTool->createSchema(
            [
            $this->_em->getClassMetadata(DDC2415ParentEntity::class),
            $this->_em->getClassMetadata(DDC2415ChildEntity::class),
            ]
        );
    }

    public function testTicket()
    {
        $parentMetadata  = $this->_em->getClassMetadata(DDC2415ParentEntity::class);
        $childMetadata   = $this->_em->getClassMetadata(DDC2415ChildEntity::class);

        $this->assertEquals($parentMetadata->generatorType, $childMetadata->generatorType);
        $this->assertEquals($parentMetadata->customGeneratorDefinition, $childMetadata->customGeneratorDefinition);
        $this->assertEquals(DDC2415Generator::class, $parentMetadata->customGeneratorDefinition['class']);

        $e1 = new DDC2415ChildEntity("ChildEntity 1");
        $e2 = new DDC2415ChildEntity("ChildEntity 2");

        $this->_em->persist($e1);
        $this->_em->persist($e2);
        $this->_em->flush();
        $this->_em->clear();

        $this->assertEquals(md5($e1->getName()), $e1->getId());
        $this->assertEquals(md5($e2->getName()), $e2->getId());
    }
}

class DDC2415ParentEntity
{
    protected $id;

    public function getId()
    {
        return $this->id;
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
            'id'        => true,
            'fieldName' => 'id',
            'type'      => 'string',
            ]
        );

        $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_CUSTOM);
        $metadata->setCustomGeneratorDefinition(['class' => DDC2415Generator::class]);

        $metadata->isMappedSuperclass = true;
    }
}

class DDC2415ChildEntity extends DDC2415ParentEntity
{
    protected $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public static function loadMetadata(ClassMetadata $metadata)
    {
        $metadata->mapField(
            [
            'fieldName' => 'name',
            'type'      => 'string',
            ]
        );
    }
}

class DDC2415Generator extends AbstractIdGenerator
{
    public function generate(EntityManager $em, $entity)
    {
        return md5($entity->getName());
    }
}
