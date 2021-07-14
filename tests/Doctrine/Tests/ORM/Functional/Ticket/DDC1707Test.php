<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\HasLifecycleCallbacks;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\PostLoad;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1707
 */
class DDC1707Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1509File::class),
                    $this->_em->getClassMetadata(DDC1509Picture::class),
                ]
            );
        } catch (Exception $ignored) {
        }
    }

    public function testPostLoadOnChild(): void
    {
        $class  = $this->_em->getClassMetadata(DDC1707Child::class);
        $entity = new DDC1707Child();

        $class->invokeLifecycleCallbacks(Events::postLoad, $entity);

        $this->assertTrue($entity->postLoad);
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({"c": "DDC1707Child"})
 * @HasLifecycleCallbacks
 */
abstract class DDC1707Base
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /** @var bool */
    public $postLoad = false;

    /**
     * @PostLoad
     */
    public function onPostLoad(): void
    {
        $this->postLoad = true;
    }
}
/**
 * @Entity
 */
class DDC1707Child extends DDC1707Base
{
}
