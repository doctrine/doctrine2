<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use DateTime;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_flight")
 * @Cache("NONSTRICT_READ_WRITE")
 */
class Flight
{
    /**
     * @var City
     * @Id
     * @Cache
     * @ManyToOne(targetEntity="City")
     * @JoinColumn(name="leaving_from_city_id", referencedColumnName="id")
     */
    protected $leavingFrom;

    /**
     * @var City
     * @Id
     * @Cache
     * @ManyToOne(targetEntity="City")
     * @JoinColumn(name="going_to_city_id", referencedColumnName="id")
     */
    protected $goingTo;

    /**
     * @var DateTime
     * @Column(type="date")
     */
    protected $departure;

    public function __construct(City $leavingFrom, City $goingTo)
    {
        $this->goingTo     = $goingTo;
        $this->leavingFrom = $leavingFrom;
        $this->departure   = new DateTime();
    }

    public function getLeavingFrom(): City
    {
        return $this->leavingFrom;
    }

    public function getGoingTo(): City
    {
        return $this->goingTo;
    }

    public function getDeparture(): DateTime
    {
        return $this->departure;
    }

    public function setDeparture(DateTime $departure): void
    {
        $this->departure = $departure;
    }
}
