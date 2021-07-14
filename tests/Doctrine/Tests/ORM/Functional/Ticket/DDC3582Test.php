<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

class DDC3582Test extends OrmFunctionalTestCase
{
    public function testNestedEmbeddablesAreHydratedWithProperClass(): void
    {
        $this->_schemaTool->createSchema([$this->_em->getClassMetadata(DDC3582Entity::class)]);
        $this->_em->persist(new DDC3582Entity('foo'));
        $this->_em->flush();
        $this->_em->clear();

        $entity = $this->_em->find(DDC3582Entity::class, 'foo');
        assert($entity instanceof DDC3582Entity);

        $this->assertInstanceOf(DDC3582Embeddable1::class, $entity->embeddable1);
        $this->assertInstanceOf(DDC3582Embeddable2::class, $entity->embeddable1->embeddable2);
        $this->assertInstanceOf(DDC3582Embeddable3::class, $entity->embeddable1->embeddable2->embeddable3);
    }
}

/** @Entity */
class DDC3582Entity
{
    /**
     * @var string
     * @Column
     * @Id
     */
    private $id;

    /**
     * @var DDC3582Embeddable1
     * @Embedded(class="DDC3582Embeddable1")
     */
    public $embeddable1;

    public function __construct(string $id)
    {
        $this->id          = $id;
        $this->embeddable1 = new DDC3582Embeddable1();
    }
}

/** @Embeddable */
class DDC3582Embeddable1
{
    /**
     * @var DDC3582Embeddable2
     * @Embedded(class="DDC3582Embeddable2")
     */
    public $embeddable2;

    public function __construct()
    {
        $this->embeddable2 = new DDC3582Embeddable2();
    }
}

/** @Embeddable */
class DDC3582Embeddable2
{
    /**
     * @var DDC3582Embeddable3
     * @Embedded(class="DDC3582Embeddable3")
     */
    public $embeddable3;

    public function __construct()
    {
        $this->embeddable3 = new DDC3582Embeddable3();
    }
}

/** @Embeddable */
class DDC3582Embeddable3
{
    /**
     * @var string
     * @Column
     */
    public $embeddedValue = 'foo';
}
