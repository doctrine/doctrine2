<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Taxi;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="taxi_driver")
 */
class Driver
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var string
     * @Column(type="string", length=255);
     */
    private $name;

    /**
     * @psalm-var Collection<int, Ride>
     * @OneToMany(targetEntity="Ride", mappedBy="driver")
     */
    private $freeDriverRides;

    /**
     * @psalm-var Collection<int, PaidRide>
     * @OneToMany(targetEntity="PaidRide", mappedBy="driver")
     */
    private $driverRides;

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
