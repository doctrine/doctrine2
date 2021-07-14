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
class RoutingLeg
{
    /**
     * @var int
     * @Id @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var RoutingLocation
     * @ManyToOne(targetEntity="RoutingLocation")
     * @JoinColumn(name="from_id", referencedColumnName="id")
     */
    public $fromLocation;

    /**
     * @var RoutingLocation
     * @ManyToOne(targetEntity="RoutingLocation")
     * @JoinColumn(name="to_id", referencedColumnName="id")
     */
    public $toLocation;

    /**
     * @var DateTime
     * @Column(type="datetime")
     */
    public $departureDate;

    /**
     * @var DateTime
     * @Column(type="datetime")
     */
    public $arrivalDate;
}
