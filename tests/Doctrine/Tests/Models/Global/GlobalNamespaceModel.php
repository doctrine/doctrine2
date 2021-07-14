<?php

declare(strict_types=1);

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="articles")
 */
class DoctrineGlobalArticle
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    protected $id;

    /**
     * @var string
     * @Column(type="string")
     */
    protected $headline;

    /**
     * @var string
     * @Column(type="text")
     */
    protected $text;

    /**
     * @psalm-var Collection<int, DoctrineGlobalUser>
     * @ManyToMany(targetEntity="DoctrineGlobalUser")
     * @JoinTable(name="author_articles",
     *      joinColumns={@JoinColumn(name="article_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="author_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected $author;

    /**
     * @psalm-var Collection<int, DoctrineGlobalUser>
     * @ManyToMany(targetEntity="DoctrineGlobalUser")
     * @JoinTable(name="editor_articles",
     *      joinColumns={@JoinColumn(name="article_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="editor_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected $editor;
}

/**
 * @Entity
 * @Table(name="users")
 */
class DoctrineGlobalUser
{
    /**
     * @Id
     * @Column(type="integer")
     * @var int
     */
    private $id;

    /**
     * @Column(type="string", length=64)
     * @var string
     */
    private $username;

    /**
     * @Column(type="string", length=128)
     * @var string
     */
    private $email;
}
