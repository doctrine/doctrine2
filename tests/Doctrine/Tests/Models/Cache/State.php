<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_state")
 * @Cache("NONSTRICT_READ_WRITE")
 */
class State
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    protected $id;

    /**
     * @var string
     * @Column(unique=true)
     */
    protected $name;

    /**
     * @var Country|null
     * @Cache
     * @ManyToOne(targetEntity="Country")
     * @JoinColumn(name="country_id", referencedColumnName="id")
     */
    protected $country;

    /**
     * @psalm-var Collection<int, City>
     * @Cache("NONSTRICT_READ_WRITE")
     * @OneToMany(targetEntity="City", mappedBy="state")
     */
    protected $cities;

    public function __construct(string $name, ?Country $country = null)
    {
        $this->name    = $name;
        $this->country = $country;
        $this->cities  = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(Country $country): void
    {
        $this->country = $country;
    }

    /**
     * @psalm-return Collection<int, City>
     */
    public function getCities(): Collection
    {
        return $this->cities;
    }

    /**
     * @psalm-param Collection<int, City> $cities
     */
    public function setCities(Collection $cities): void
    {
        $this->cities = $cities;
    }

    public function addCity(City $city): void
    {
        $this->cities[] = $city;
    }
}
