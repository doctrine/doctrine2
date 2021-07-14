<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Routing;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;

/**
 * @Entity
 */
class RoutingRoute
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var Collection<int, RoutingLeg>
     * @ManyToMany(targetEntity="RoutingLeg", cascade={"all"})
     * @JoinTable(name="RoutingRouteLegs",
     *     joinColumns={@JoinColumn(name="route_id", referencedColumnName="id")},
     *     inverseJoinColumns={@JoinColumn(name="leg_id", referencedColumnName="id", unique=true)}
     * )
     * @OrderBy({"departureDate" = "ASC"})
     */
    public $legs;

    /**
     * @var Collection<int, RoutingRouteBooking>
     * @OneToMany(targetEntity="RoutingRouteBooking", mappedBy="route")
     * @OrderBy({"passengerName" = "ASC"})
     */
    public $bookings = [];

    public function __construct()
    {
        $this->legs = new ArrayCollection();
    }
}
