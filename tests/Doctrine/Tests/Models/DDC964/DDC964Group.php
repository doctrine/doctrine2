<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC964;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToMany;

/**
 * @Entity
 */
class DDC964Group
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
     * @psalm-var ArrayCollection<int, DDC964User>
     * @ManyToMany(targetEntity="DDC964User", mappedBy="groups")
     */
    private $users;

    public function __construct($name = null)
    {
        $this->name  = $name;
        $this->users = new ArrayCollection();
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function addUser(DDC964User $user): void
    {
        $this->users[] = $user;
    }

    /**
     * @psalm-return ArrayCollection<int, DDC964User>
     */
    public function getUsers(): ArrayCollection
    {
        return $this->users;
    }
}
