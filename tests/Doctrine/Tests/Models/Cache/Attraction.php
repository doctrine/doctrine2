<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Cache("NONSTRICT_READ_WRITE")
 * @Entity
 * @Table("cache_attraction")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorMap({
 *  1  = "Restaurant",
 *  2  = "Beach",
 *  3  = "Bar"
 * })
 */
abstract class Attraction
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
     * @var City
     * @Cache
     * @ManyToOne(targetEntity="City", inversedBy="attractions")
     * @JoinColumn(name="city_id", referencedColumnName="id")
     */
    protected $city;

    /**
     * @psalm-var Collection<int, AttractionInfo>
     * @Cache
     * @OneToMany(targetEntity="AttractionInfo", mappedBy="attraction")
     */
    protected $infos;

    public function __construct(string $name, City $city)
    {
        $this->name  = $name;
        $this->city  = $city;
        $this->infos = new ArrayCollection();
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

    public function getCity(): City
    {
        return $this->city;
    }

    public function setCity(City $city): void
    {
        $this->city = $city;
    }

    /**
     * @psalm-return Collection<int, AttractionInfo>
     */
    public function getInfos(): Collection
    {
        return $this->infos;
    }

    public function addInfo(AttractionInfo $info): void
    {
        if (! $this->infos->contains($info)) {
            $this->infos->add($info);
        }
    }
}
