<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Quote;

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
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="`quote-user`")
 */
class User
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer", name="`user-id`")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", name="`user-name`")
     */
    public $name;

    /**
     * @psalm-var Collection<int, Phone>
     * @OneToMany(targetEntity="Phone", mappedBy="user", cascade={"persist"})
     */
    public $phones;

    /**
     * @var Address
     * @JoinColumn(name="`address-id`", referencedColumnName="`address-id`")
     * @OneToOne(targetEntity="Address", mappedBy="user", cascade={"persist"}, fetch="EAGER")
     */
    public $address;

    /**
     * @psalm-var Collection<int, Group>
     * @ManyToMany(targetEntity="Group", inversedBy="users", cascade={"all"}, fetch="EXTRA_LAZY")
     * @JoinTable(name="`quote-users-groups`",
     *      joinColumns={
     *          @JoinColumn(
     *              name="`user-id`",
     *              referencedColumnName="`user-id`"
     *          )
     *      },
     *      inverseJoinColumns={
     *          @JoinColumn(
     *              name="`group-id`",
     *              referencedColumnName="`group-id`"
     *          )
     *      }
     * )
     */
    public $groups;

    public function __construct()
    {
        $this->phones = new ArrayCollection();
        $this->groups = new ArrayCollection();
    }

    /**
     * @psalm-return Collection<int, Phone>
     */
    public function getPhones(): Collection
    {
        return $this->phones;
    }

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    /**
     * @psalm-return Collection<int, Group>
     */
    public function getGroups(): Collection
    {
        return $this->groups;
    }

    public function setAddress(Address $address): void
    {
        if ($this->address !== $address) {
            $this->address = $address;
            $address->setUser($this);
        }
    }
}
