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
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Cache
 * @Entity
 * @Table("cache_traveler")
 */
class Traveler
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
     * @Column
     */
    protected $name;

    /**
     * @psalm-var Collection<int, Travel>
     * @Cache("NONSTRICT_READ_WRITE")
     * @OneToMany(targetEntity="Travel", mappedBy="traveler", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    public $travels;

    /**
     * @var TravelerProfile
     * @Cache
     * @OneToOne(targetEntity="TravelerProfile")
     */
     protected $profile;

    public function __construct(string $name)
    {
        $this->name    = $name;
        $this->travels = new ArrayCollection();
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

    public function getProfile(): TravelerProfile
    {
        return $this->profile;
    }

    public function setProfile(TravelerProfile $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * @psalm-return Collection<int, Travel>
     */
    public function getTravels(): Collection
    {
        return $this->travels;
    }

    public function addTravel(Travel $item): void
    {
        if (! $this->travels->contains($item)) {
            $this->travels->add($item);
        }

        if ($item->getTraveler() !== $this) {
            $item->setTraveler($this);
        }
    }

    public function removeTravel(Travel $item): void
    {
        $this->travels->removeElement($item);
    }
}
