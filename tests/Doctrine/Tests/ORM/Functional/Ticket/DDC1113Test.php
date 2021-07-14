<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

/**
 * @group DDC-1113
 * @group DDC-1306
 */
class DDC1113Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1113Engine::class),
                    $this->_em->getClassMetadata(DDC1113Vehicle::class),
                    $this->_em->getClassMetadata(DDC1113Car::class),
                    $this->_em->getClassMetadata(DDC1113Bus::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    public function testIssue(): void
    {
        $car         = new DDC1113Car();
        $car->engine = new DDC1113Engine();

        $bus         = new DDC1113Bus();
        $bus->engine = new DDC1113Engine();

        $this->_em->persist($car);
        $this->_em->flush();

        $this->_em->persist($bus);
        $this->_em->flush();

        $this->_em->remove($bus);
        $this->_em->remove($car);
        $this->_em->flush();

        self::assertEmpty($this->_em->getRepository(DDC1113Car::class)->findAll());
        self::assertEmpty($this->_em->getRepository(DDC1113Bus::class)->findAll());
        self::assertEmpty($this->_em->getRepository(DDC1113Engine::class)->findAll());
    }
}

/**
 * @Entity
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({"vehicle" = "DDC1113Vehicle", "car" = "DDC1113Car", "bus" = "DDC1113Bus"})
 */
class DDC1113Vehicle
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC1113Vehicle
     * @ManyToOne(targetEntity="DDC1113Vehicle")
     */
    public $parent;

    /**
     * @var DDC1113Engine
     * @OneToOne(targetEntity="DDC1113Engine", cascade={"persist", "remove"})
     */
    public $engine;
}

/**
 * @Entity
 */
class DDC1113Car extends DDC1113Vehicle
{
}

/**
 * @Entity
 */
class DDC1113Bus extends DDC1113Vehicle
{
}

/**
 * @Entity
 */
class DDC1113Engine
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;
}
