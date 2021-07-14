<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\NonPublicSchemaJoins;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\OrderBy;
use Doctrine\ORM\Mapping\Table;

/**
 * Doctrine\Tests\Models\NonPublicSchemaJoins\User
 *
 * @Entity
 * @Table(name="readers.user")
 */
class User
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id
     */
    public $id;

    /**
     * @ManyToMany(targetEntity="Doctrine\Tests\Models\NonPublicSchemaJoins\User", inversedBy="authors")
     * @JoinTable(
     *      name="author_reader",
     *      schema="readers",
     *      joinColumns={@JoinColumn(name="author_id", referencedColumnName="id")},
     *      inverseJoinColumns={@JoinColumn(name="reader_id", referencedColumnName="id")}
     * )
     * @var User[]
     */
    public $readers;

    /**
     * @ManyToMany(targetEntity="Doctrine\Tests\Models\NonPublicSchemaJoins\User", mappedBy="readers")
     * @var User[]
     */
    public $authors;
}
