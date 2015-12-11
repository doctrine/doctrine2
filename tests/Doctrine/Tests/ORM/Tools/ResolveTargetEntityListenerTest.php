<?php

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Events;

class ResolveTargetEntityListenerTest extends \Doctrine\Tests\OrmTestCase
{
    /**
     * @var EntityManager
     */
    private $em = null;

    /**
     * @var ResolveTargetEntityListener
     */
    private $listener = null;

    /**
     * @var ClassMetadataFactory
     */
    private $factory = null;

    public function setUp()
    {
        $annotationDriver = $this->createAnnotationDriver();

        $this->em = $this->_getTestEntityManager();
        $this->em->getConfiguration()->setMetadataDriverImpl($annotationDriver);
        $this->factory = $this->em->getMetadataFactory();
        $this->listener = new ResolveTargetEntityListener();
    }

    /**
     * @group DDC-1544
     */
    public function testResolveTargetEntityListenerCanResolveTargetEntity()
    {
        $evm = $this->em->getEventManager();
        $this->listener->addResolveTargetEntity(
            'Doctrine\Tests\ORM\Tools\ResolveTargetInterface',
            'Doctrine\Tests\ORM\Tools\ResolveTargetEntity',
            array()
        );
        $this->listener->addResolveTargetEntity(
            'Doctrine\Tests\ORM\Tools\TargetInterface',
            'Doctrine\Tests\ORM\Tools\TargetEntity',
            array()
        );
        $evm->addEventSubscriber($this->listener);

        $cm   = $this->factory->getMetadataFor('Doctrine\Tests\ORM\Tools\ResolveTargetEntity');
        $meta = $cm->associationMappings;

        $this->assertSame('Doctrine\Tests\ORM\Tools\TargetEntity', $meta['manyToMany']['targetEntity']);
        $this->assertSame('Doctrine\Tests\ORM\Tools\ResolveTargetEntity', $meta['manyToOne']['targetEntity']);
        $this->assertSame('Doctrine\Tests\ORM\Tools\ResolveTargetEntity', $meta['oneToMany']['targetEntity']);
        $this->assertSame('Doctrine\Tests\ORM\Tools\TargetEntity', $meta['oneToOne']['targetEntity']);

        $this->assertSame($cm, $this->factory->getMetadataFor('Doctrine\Tests\ORM\Tools\ResolveTargetInterface'));
    }

    /**
     * @group DDC-3385
     * @group 1181
     * @group 385
     */
    public function testResolveTargetEntityListenerCanRetrieveTargetEntityByInterfaceName()
    {
        $this->listener->addResolveTargetEntity(
            'Doctrine\Tests\ORM\Tools\ResolveTargetInterface',
            'Doctrine\Tests\ORM\Tools\ResolveTargetEntity',
            array()
        );

        $this->em->getEventManager()->addEventSubscriber($this->listener);

        $cm = $this->factory->getMetadataFor('Doctrine\Tests\ORM\Tools\ResolveTargetInterface');

        $this->assertSame($this->factory->getMetadataFor('Doctrine\Tests\ORM\Tools\ResolveTargetEntity'), $cm);
    }

    /**
     * @group DDC-2109
     */
    public function testAssertTableColumnsAreNotAddedInManyToMany()
    {
        $evm = $this->em->getEventManager();
        $this->listener->addResolveTargetEntity(
            'Doctrine\Tests\ORM\Tools\ResolveTargetInterface',
            'Doctrine\Tests\ORM\Tools\ResolveTargetEntity',
            array()
        );
        $this->listener->addResolveTargetEntity(
            'Doctrine\Tests\ORM\Tools\TargetInterface',
            'Doctrine\Tests\ORM\Tools\TargetEntity',
            array()
        );

        $evm->addEventListener(Events::loadClassMetadata, $this->listener);
        $cm = $this->factory->getMetadataFor('Doctrine\Tests\ORM\Tools\ResolveTargetEntity');
        $meta = $cm->associationMappings['manyToMany'];

        $this->assertSame('Doctrine\Tests\ORM\Tools\TargetEntity', $meta['targetEntity']);
        $this->assertEquals(array('resolvetargetentity_id', 'targetinterface_id'), $meta['joinTableColumns']);
    }
    
    /**
     * @group DDC-4015
     */
    public function testInterfaceInDQL(){
        $this->listener->addResolveTargetEntity(
            'Doctrine\Tests\ORM\Tools\TargetInterface',
            'Doctrine\Tests\ORM\Tools\TargetEntity',
            array()
        );
        
        $this->em->getEventManager()->addEventSubscriber($this->listener);
                                                                                  
        $query = $this->em->createQuery('SELECT rti FROM Doctrine\Tests\ORM\Tools\TargetInterface rti');
        $this->assertEquals('SELECT t0_.id AS id_0 FROM TargetEntity t0_', $query->getSQL());
    }
}

interface ResolveTargetInterface
{
    public function getId();
}

interface TargetInterface extends ResolveTargetInterface
{
}

/**
 * @Entity
 */
class ResolveTargetEntity implements ResolveTargetInterface
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ManyToMany(targetEntity="Doctrine\Tests\ORM\Tools\TargetInterface")
     */
    private $manyToMany;

    /**
     * @ManyToOne(targetEntity="Doctrine\Tests\ORM\Tools\ResolveTargetInterface", inversedBy="oneToMany")
     */
    private $manyToOne;

    /**
     * @OneToMany(targetEntity="Doctrine\Tests\ORM\Tools\ResolveTargetInterface", mappedBy="manyToOne")
     */
    private $oneToMany;

    /**
     * @OneToOne(targetEntity="Doctrine\Tests\ORM\Tools\TargetInterface")
     * @JoinColumn(name="target_entity_id", referencedColumnName="id")
     */
    private $oneToOne;

    public function getId()
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class TargetEntity implements TargetInterface
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    public function getId()
    {
        return $this->id;
    }
}
