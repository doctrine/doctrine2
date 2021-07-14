<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Cache
 * @Entity
 * @Table("cache_travel")
 */
class Travel
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    protected $id;

    /**
     * @var DateTime
     * @Column(type="date")
     */
    protected $createdAt;

    /**
     * @var Traveler
     * @Cache
     * @ManyToOne(targetEntity="Traveler", inversedBy="travels")
     * @JoinColumn(name="traveler_id", referencedColumnName="id")
     */
    protected $traveler;

    /**
     * @psalm-var Collection<int, City>
     * @Cache
     * @ManyToMany(targetEntity="City", inversedBy="travels", cascade={"persist", "remove"})
     * @JoinTable(name="cache_visited_cities",
     *  joinColumns={
     *      @JoinColumn(name="travel_id", referencedColumnName="id")
     *  },
     *  inverseJoinColumns={
     *      @JoinColumn(name="city_id", referencedColumnName="id")
     *  }
     * )
     */
    public $visitedCities;

    public function __construct(Traveler $traveler)
    {
        $this->traveler      = $traveler;
        $this->createdAt     = new DateTime('now');
        $this->visitedCities = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTraveler(): Traveler
    {
        return $this->traveler;
    }

    public function setTraveler(Traveler $traveler): void
    {
        $this->traveler = $traveler;
    }

    /**
     * @psalm-return Collection<int, City>
     */
    public function getVisitedCities(): Collection
    {
        return $this->visitedCities;
    }

    public function addVisitedCity(City $city): void
    {
        $this->visitedCities->add($city);
    }

    public function removeVisitedCity(City $city): void
    {
        $this->visitedCities->removeElement($city);
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }
}
