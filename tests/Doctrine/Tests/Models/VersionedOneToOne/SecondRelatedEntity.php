<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\VersionedOneToOne;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="second_entity")
 */
class SecondRelatedEntity
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
     * @var int
     * Version column
     * @Column(type="integer", name="version")
     * @Version
     */
    public $version;
}
