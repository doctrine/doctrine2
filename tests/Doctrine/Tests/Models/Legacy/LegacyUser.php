<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Legacy;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="legacy_users")
 */
class LegacyUser
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(name="iUserId", type="integer", nullable=false)
     */
    public $id;

    /**
     * @var string
     * @Column(name="sUsername", type="string", length=255, unique=true)
     */
    public $username;

    /**
     * @var string
     * @Column(type="string", length=255, name="name")
     */
    public $name;

    /**
     * @psalm-var Collection<int, LegacyArticle>
     * @OneToMany(targetEntity="LegacyArticle", mappedBy="user")
     */
    public $articles;

    /**
     * @psalm-var Collection<int, LegacyUserReference>
     * @OneToMany(targetEntity="LegacyUserReference", mappedBy="_source", cascade={"remove"})
     */
    public $references;

    /**
     * @psalm-var Collection<int, LegacyCar>
     * @ManyToMany(targetEntity="LegacyCar", inversedBy="users", cascade={"persist", "merge"})
     * @JoinTable(name="legacy_users_cars",
     *      joinColumns={@JoinColumn(name="iUserId", referencedColumnName="iUserId")},
     *      inverseJoinColumns={@JoinColumn(name="iCarId", referencedColumnName="iCarId")}
     * )
     */
    public $cars;

    public function __construct()
    {
        $this->articles   = new ArrayCollection();
        $this->references = new ArrayCollection();
        $this->cars       = new ArrayCollection();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function addArticle(LegacyArticle $article): void
    {
        $this->articles[] = $article;
        $article->setAuthor($this);
    }

    public function addReference(LegacyUserReference $reference): void
    {
        $this->references[] = $reference;
    }

    /**
     * @psalm-return Collection<int, LegacyUserReference>
     */
    public function references(): Collection
    {
        return $this->references;
    }

    public function addCar(LegacyCar $car): void
    {
        $this->cars[] = $car;
        $car->addUser($this);
    }

    /**
     * @psalm-return Collection<int, LegacyCar>
     */
    public function getCars(): Collection
    {
        return $this->cars;
    }
}
