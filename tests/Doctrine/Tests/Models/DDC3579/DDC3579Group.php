<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3579;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;

/**
 * @Entity
 */
class DDC3579Group
{
    /**
     * @var int
     * @GeneratedValue
     * @Id @Column(type="integer")
     */
    private $id;

    /**
     * @var string|null
     * @Column
     */
    private $name;

    /**
     * @psalm-var Collection<int, DDC3579Admin>
     * @ManyToMany(targetEntity="DDC3579Admin", mappedBy="groups")
     */
    private $admins;

    public function __construct(?string $name = null)
    {
        $this->name   = $name;
        $this->admins = new ArrayCollection();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function addAdmin(DDC3579Admin $admin): void
    {
        $this->admins[] = $admin;
    }

    /**
     * @psalm-return Collection<int, DDC3579Admin>
     */
    public function getAdmins(): Collection
    {
        return $this->admins;
    }
}
