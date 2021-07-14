<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CustomType;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="customtype_parents")
 */
class CustomTypeParent
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var int
     * @Column(type="negative_to_positive", nullable=true)
     */
    public $customInteger;

    /**
     * @var CustomTypeChild
     * @OneToOne(targetEntity="Doctrine\Tests\Models\CustomType\CustomTypeChild", cascade={"persist", "remove"})
     */
    public $child;

    /**
     * @psalm-var Collection<int, CustomTypeParent>
     * @ManyToMany(targetEntity="Doctrine\Tests\Models\CustomType\CustomTypeParent", mappedBy="myFriends")
     */
    private $friendsWithMe;

    /**
     * @psalm-var Collection<int, CustomTypeParent>
     * @ManyToMany(targetEntity="Doctrine\Tests\Models\CustomType\CustomTypeParent", inversedBy="friendsWithMe")
     * @JoinTable(
     *     name="customtype_parent_friends",
     *     joinColumns={@JoinColumn(name="customtypeparent_id", referencedColumnName="id")},
     *     inverseJoinColumns={@JoinColumn(name="friend_customtypeparent_id", referencedColumnName="id")}
     * )
     */
    private $myFriends;

    public function __construct()
    {
        $this->friendsWithMe = new ArrayCollection();
        $this->myFriends     = new ArrayCollection();
    }

    public function addMyFriend(CustomTypeParent $friend): void
    {
        $this->getMyFriends()->add($friend);
        $friend->addFriendWithMe($this);
    }

    /**
     * @psalm-return Collection<int, CustomTypeParent>
     */
    public function getMyFriends(): Collection
    {
        return $this->myFriends;
    }

    public function addFriendWithMe(CustomTypeParent $friend): void
    {
        $this->getFriendsWithMe()->add($friend);
    }

    /**
     * @psalm-return Collection<int, CustomTypeParent>
     */
    public function getFriendsWithMe()
    {
        return $this->friendsWithMe;
    }
}
