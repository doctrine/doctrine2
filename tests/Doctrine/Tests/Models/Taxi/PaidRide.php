<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Taxi;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * Same as Ride but with an extra column that is not part of the composite primary key
 *
 * @Entity
 * @Table(name="taxi_paid_ride")
 */
class PaidRide
{
    /**
     * @var Driver
     * @Id
     * @ManyToOne(targetEntity="Driver", inversedBy="driverRides")
     * @JoinColumn(name="driver_id", referencedColumnName="id")
     */
    private $driver;

    /**
     * @var Car
     * @Id
     * @ManyToOne(targetEntity="Car", inversedBy="carRides")
     * @JoinColumn(name="car", referencedColumnName="brand")
     */
    private $car;

    /**
     * @var float
     * @Column(type="decimal", precision=6, scale=2)
     */
    private $fare;

    public function __construct(Driver $driver, Car $car)
    {
        $this->driver = $driver;
        $this->car    = $car;
    }

    public function setFare($fare): void
    {
        $this->fare = $fare;
    }
}
