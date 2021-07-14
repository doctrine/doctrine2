<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\VersionedManyToOne;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="versioned_many_to_one_article")
 */
class Article
{
    /**
     * @var int
     * @Id
     * @Column(name="id", type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(name="name")
     */
    public $name;

    /**
     * @var Category
     * @ManyToOne(targetEntity="Category", cascade={"merge", "persist"})
     */
    public $category;

    /**
     * Version column
     *
     * @var int
     * @Column(type="integer", name="version")
     * @Version
     */
    public $version;
}
