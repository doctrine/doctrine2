<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Annotation as ORM;

/**
 * @group DDC-1113
 * @group DDC-1306
 */
class DDC1113Test extends \Doctrine\Tests\OrmFunctionalTestCase
{
    public function setUp()
    {
        parent::setUp();

        try {
            $this->schemaTool->createSchema(
                [
                    $this->em->getClassMetadata(DDC1113Engine::class),
                    $this->em->getClassMetadata(DDC1113Vehicle::class),
                    $this->em->getClassMetadata(DDC1113Car::class),
                    $this->em->getClassMetadata(DDC1113Bus::class),
                ]
            );
        } catch (\Exception $e) {
        }
    }

    public function testIssue()
    {
        $car = new DDC1113Car();
        $car->engine = new DDC1113Engine();

        $bus = new DDC1113Bus();
        $bus->engine = new DDC1113Engine();

        $this->em->persist($car);
        $this->em->flush();

        $this->em->persist($bus);
        $this->em->flush();

        $this->em->remove($bus);
        $this->em->remove($car);
        $this->em->flush();

        self::assertEmpty($this->em->getRepository(DDC1113Car::class)->findAll());
        self::assertEmpty($this->em->getRepository(DDC1113Bus::class)->findAll());
        self::assertEmpty($this->em->getRepository(DDC1113Engine::class)->findAll());
    }
}

/**
 * @ORM\Entity
 * @ORM\InheritanceType("SINGLE_TABLE")
 * @ORM\DiscriminatorMap({"vehicle" = "DDC1113Vehicle", "car" = "DDC1113Car", "bus" = "DDC1113Bus"})
 */
class DDC1113Vehicle
{

    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    public $id;

    /**
     * @ORM\ManyToOne(targetEntity="DDC1113Vehicle")
     */
    public $parent;

    /** @ORM\OneToOne(targetEntity="DDC1113Engine", cascade={"persist", "remove"}) */
    public $engine;

}

/**
 * @ORM\Entity
 */
class DDC1113Car extends DDC1113Vehicle
{

}

/**
 * @ORM\Entity
 */
class DDC1113Bus extends DDC1113Vehicle
{

}

/**
 * @ORM\Entity
 */
class DDC1113Engine
{

    /** @ORM\Id @ORM\GeneratedValue @ORM\Column(type="integer") */
    public $id;

}

