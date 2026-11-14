<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket\GH12505;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'gh_12505_garage')]
class Garage
{
    #[Id]
    #[GeneratedValue]
    #[Column(name: 'id', type: 'integer')]
    private int $id;

    /**
     * Indexed by "id", which {@see Car} inherits from the {@see Vehicle} root class.
     *
     * @var Collection<int, Car>
     */
    #[ManyToMany(targetEntity: Car::class, indexBy: 'id')]
    #[JoinTable(name: 'gh_12505_garage_cars')]
    private Collection $cars;

    public function __construct()
    {
        $this->cars = new ArrayCollection();
    }

    public function addCar(Car $car): void
    {
        $this->cars[] = $car;
    }

    /** @return Collection<int, Car> */
    public function getCars(): Collection
    {
        return $this->cars;
    }
}
