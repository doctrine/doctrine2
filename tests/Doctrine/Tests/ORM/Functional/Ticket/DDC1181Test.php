<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC1181Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema(
            [
                $this->_em->getClassMetadata(DDC1181Hotel::class),
                $this->_em->getClassMetadata(DDC1181Booking::class),
                $this->_em->getClassMetadata(DDC1181Room::class),
            ]
        );
    }

    /**
     * @group DDC-1181
     */
    public function testIssue(): void
    {
        $hotel = new DDC1181Hotel();
        $room1 = new DDC1181Room();
        $room2 = new DDC1181Room();

        $this->_em->persist($hotel);
        $this->_em->persist($room1);
        $this->_em->persist($room2);
        $this->_em->flush();

        $booking1          = new DDC1181Booking();
        $booking1->hotel   = $hotel;
        $booking1->room    = $room1;
        $booking2          = new DDC1181Booking();
        $booking2->hotel   = $hotel;
        $booking2->room    = $room2;
        $hotel->bookings[] = $booking1;
        $hotel->bookings[] = $booking2;

        $this->_em->persist($booking1);
        $this->_em->persist($booking2);
        $this->_em->flush();

        $this->_em->remove($hotel);
        $this->_em->flush();

        self::assertEmpty($this->_em->getRepository(DDC1181Booking::class)->findAll());
    }
}

/**
 * @Entity
 */
class DDC1181Hotel
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @oneToMany(targetEntity="DDC1181Booking", mappedBy="hotel", cascade={"remove"})
     * @var Booking[]
     */
    public $bookings;
}

/**
 * @Entity
 */
class DDC1181Booking
{
    /**
     * @var Hotel
     * @Id
     * @ManyToOne(targetEntity="DDC1181Hotel", inversedBy="bookings")
     * @JoinColumns({
     *   @JoinColumn(name="hotel_id", referencedColumnName="id")
     * })
     */
    public $hotel;
    /**
     * @var Room
     * @Id
     * @ManyToOne(targetEntity="DDC1181Room")
     * @JoinColumns({
     *   @JoinColumn(name="room_id", referencedColumnName="id")
     * })
     */
    public $room;
}

/**
 * @Entity
 */
class DDC1181Room
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}
