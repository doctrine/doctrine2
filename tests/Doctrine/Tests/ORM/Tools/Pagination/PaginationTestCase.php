<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Tools\Pagination;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\OrmTestCase;

abstract class PaginationTestCase extends OrmTestCase
{
    /** @var EntityManagerInterface */
    public $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->getTestEntityManager();
    }
}


/**
 * @Entity
 */
class MyBlogPost
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var Author
     * @ManyToOne(targetEntity="Author")
     */
    public $author;

    /**
     * @var Category
     * @ManyToOne(targetEntity="Category")
     */
    public $category;

    /**
     * @var string
     * @Column(type="string")
     */
    public $title;
}

/**
 * @Entity
 */
class MyAuthor
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}

/**
 * @Entity
 */
class MyCategory
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}


/**
 * @Entity
 */
class BlogPost
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var Author
     * @ManyToOne(targetEntity="Author")
     */
    public $author;

    /**
     * @var Category
     * @ManyToOne(targetEntity="Category")
     */
    public $category;
}

/**
 * @Entity
 */
class Author
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;
}

/**
 * @Entity
 */
class Person
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var string
     * @Column(type="string")
     */
    public $name;

    /**
     * @var string
     * @Column(type="string")
     */
    public $biography;
}

/**
 * @Entity
 */
class Category
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;
}


/** @Entity @Table(name="groups") */
class Group
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, User>
     * @ManyToMany(targetEntity="User", mappedBy="groups")
     */
    public $users;
}

/** @Entity */
class User
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @psalm-var Collection<int, Group>
     * @ManyToMany(targetEntity="Group", inversedBy="users")
     * @JoinTable(
     * name="user_group",
     * joinColumns = {@JoinColumn(name="user_id", referencedColumnName="id")},
     * inverseJoinColumns = {@JoinColumn(name="group_id", referencedColumnName="id")}
     * )
     */
    public $groups;

    /**
     * @var Avatar
     * @OneToOne(targetEntity="Avatar", mappedBy="user")
     */
    public $avatar;
}

/** @Entity */
class Avatar
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    public $id;

    /**
     * @var User
     * @OneToOne(targetEntity="User", inversedBy="avatar")
     * @JoinColumn(name="user_id", referencedColumnName="id")
     */
    public $user;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $image;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $imageHeight;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $imageWidth;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $imageAltDesc;
}

/** @MappedSuperclass */
abstract class Identified
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    private $id;

    public function getId(): int
    {
        return $this->id;
    }
}

/** @Entity */
class Banner extends Identified
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $name;
}
