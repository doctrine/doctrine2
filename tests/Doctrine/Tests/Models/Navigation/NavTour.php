<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Navigation;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="navigation_tours")
 */
class NavTour
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    /**
     * @var string
     * @Column(type="string")
use Doctrine\ORM\Mapping\OrderBy;
     */
    private $name;

    /**
     * @var Collection<int, NavPointOfInterest>
     * @ManyToMany(targetEntity="NavPointOfInterest")
     * @JoinTable(name="navigation_tour_pois",
     *      joinColumns={@JoinColumn(name="tour_id", referencedColumnName="id")},
     *      inverseJoinColumns={
     *          @JoinColumn(name="poi_long", referencedColumnName="nav_long"),
     *          @JoinColumn(name="poi_lat", referencedColumnName="nav_lat")
     *      }
     * )
     */
    private $pois;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->pois = new ArrayCollection();
    }

    public function addPointOfInterest(NavPointOfInterest $poi): void
    {
        $this->pois[] = $poi;
    }

    public function getPointOfInterests(): Collection
    {
        return $this->pois;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
