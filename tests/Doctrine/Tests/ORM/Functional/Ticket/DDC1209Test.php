<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;
use Exception;

class DDC1209Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->_schemaTool->createSchema(
                [
                    $this->_em->getClassMetadata(DDC1209One::class),
                    $this->_em->getClassMetadata(DDC1209Two::class),
                    $this->_em->getClassMetadata(DDC1209Three::class),
                ]
            );
        } catch (Exception $e) {
        }
    }

    /**
     * @group DDC-1209
     */
    public function testIdentifierCanHaveCustomType(): void
    {
        $entity = new DDC1209Three();

        $this->_em->persist($entity);
        $this->_em->flush();

        self::assertSame($entity, $this->_em->find(DDC1209Three::class, $entity->date));
    }

    /**
     * @group DDC-1209
     */
    public function testCompositeIdentifierCanHaveCustomType(): void
    {
        $future1 = new DDC1209One();

        $this->_em->persist($future1);
        $this->_em->flush();

        $future2 = new DDC1209Two($future1);

        $this->_em->persist($future2);
        $this->_em->flush();

        self::assertSame(
            $future2,
            $this->_em->find(
                DDC1209Two::class,
                [
                    'future1'           => $future1,
                    'startingDatetime' => $future2->startingDatetime,
                    'duringDatetime'   => $future2->duringDatetime,
                    'endingDatetime'   => $future2->endingDatetime,
                ]
            )
        );
    }
}

/**
 * @Entity
 */
class DDC1209One
{
    /**
     * @var int
     * @Id
     * @GeneratedValue @Column(type="integer")
     */
    private $id;

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @Entity
 */
class DDC1209Two
{
    /**
     * @var DDC1209One
     * @Id
     * @ManyToOne(targetEntity="DDC1209One")
     * @JoinColumn(referencedColumnName="id", nullable=false)
     */
    private $future1;

    /**
     * @var DateTime2
     * @Id
     * @Column(type="datetime", nullable=false)
     */
    public $startingDatetime;

    /**
     * @var DateTime2
     * @Id
     * @Column(type="datetime", nullable=false)
     */
    public $duringDatetime;

    /**
     * @var DateTime2
     * @Id
     * @Column(type="datetime", nullable=false)
     */
    public $endingDatetime;

    public function __construct(DDC1209One $future1)
    {
        $this->future1          = $future1;
        $this->startingDatetime = new DateTime2();
        $this->duringDatetime   = new DateTime2();
        $this->endingDatetime   = new DateTime2();
    }
}

/**
 * @Entity
 */
class DDC1209Three
{
    /**
     * @var DateTime2
     * @Id
     * @Column(type="datetime", name="somedate")
     */
    public $date;

    public function __construct()
    {
        $this->date = new DateTime2();
    }
}

class DateTime2 extends DateTime
{
    public function __toString(): string
    {
        return $this->format('Y');
    }
}
