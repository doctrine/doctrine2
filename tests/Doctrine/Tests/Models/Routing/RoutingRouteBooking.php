<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Routing;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * @Entity
 */
class RoutingRouteBooking
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var RoutingRoute
     * @ManyToOne(targetEntity="RoutingRoute", inversedBy="bookings")
     * @JoinColumn(name="route_id", referencedColumnName="id")
     */
    public $route;

    /**
     * @var string
     * @Column(type="string")
     */
    public $passengerName = null;

    public function getPassengerName(): string
    {
        return $this->passengerName;
    }
}
