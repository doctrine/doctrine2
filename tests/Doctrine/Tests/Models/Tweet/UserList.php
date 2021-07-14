<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Tweet;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="tweet_user_list")
 */
class UserList
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
    public $listName;

    /**
     * @var User
     * @ManyToOne(targetEntity="User", inversedBy="userLists")
     */
    public $owner;
}
