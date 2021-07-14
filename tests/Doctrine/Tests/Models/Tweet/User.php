<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Tweet;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="tweet_user")
 */
class User
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @psalm-var Collection<int, Tweet>
     * @OneToMany(targetEntity="Tweet", mappedBy="author", cascade={"persist"}, fetch="EXTRA_LAZY")
     */
    public $tweets;

    /**
     * @psalm-var Collection<int, UserList>
     * @OneToMany(targetEntity="UserList", mappedBy="owner", fetch="EXTRA_LAZY", orphanRemoval=true)
     */
    public $userLists;

    public function __construct()
    {
        $this->tweets    = new ArrayCollection();
        $this->userLists = new ArrayCollection();
    }

    public function addTweet(Tweet $tweet): void
    {
        $tweet->setAuthor($this);
        $this->tweets->add($tweet);
    }

    public function addUserList(UserList $userList): void
    {
        $userList->owner = $this;
        $this->userLists->add($userList);
    }
}
