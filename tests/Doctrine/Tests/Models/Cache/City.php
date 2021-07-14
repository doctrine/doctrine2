<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * @Cache
 * @Entity
 * @Table("cache_city")
 */
#[ORM\Entity, ORM\Table(name: 'cache_city'), ORM\Cache]
class City
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    protected $id;

    /**
     * @var string
     * @Column(unique=true)
     */
    #[ORM\Column(unique: true)]
    protected $name;

    /**
     * @var State|null
     * @Cache
     * @ManyToOne(targetEntity="State", inversedBy="cities")
     * @JoinColumn(name="state_id", referencedColumnName="id")
     */
    #[ORM\Cache]
    #[ORM\ManyToOne(targetEntity: 'State', inversedBy: 'citities')]
    #[ORM\JoinColumn(name: 'state_id', referencedColumnName: 'id')]
    protected $state;

    /**
     * @var Collection<int, Travel>
     * @ManyToMany(targetEntity="Travel", mappedBy="visitedCities")
     */
    #[ORM\ManyToMany(targetEntity: 'Travel', mappedBy: 'visitedCities')]
    public $travels;

    /**
     * @psalm-var Collection<int, Attraction>
     * @Cache
     * @OrderBy({"name" = "ASC"})
     * @OneToMany(targetEntity="Attraction", mappedBy="city")
     */
    #[ORM\Cache, ORM\OrderBy(['name' => 'ASC'])]
    #[ORM\OneToMany(targetEntity: 'Attraction', mappedBy: 'city')]
    public $attractions;

    public function __construct(string $name, ?State $state = null)
    {
        $this->name        = $name;
        $this->state       = $state;
        $this->travels     = new ArrayCollection();
        $this->attractions = new ArrayCollection();
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

    public function getState(): ?State
    {
        return $this->state;
    }

    public function setState(State $state): void
    {
        $this->state = $state;
    }

    public function addTravel(Travel $travel): void
    {
        $this->travels[] = $travel;
    }

    /**
     * @psalm-return Collection<int, Travel>
     */
    public function getTravels(): Collection
    {
        return $this->travels;
    }

    public function addAttraction(Attraction $attraction): void
    {
        $this->attractions[] = $attraction;
    }

    /**
     * @psalm-return Collection<int, Attraction>
     */
    public function getAttractions(): Collection
    {
        return $this->attractions;
    }

    public static function loadMetadata(ClassMetadataInfo $metadata): void
    {
        include __DIR__ . '/../../ORM/Mapping/php/Doctrine.Tests.Models.Cache.City.php';
    }
}
