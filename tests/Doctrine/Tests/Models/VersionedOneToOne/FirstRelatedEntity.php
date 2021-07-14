<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\VersionedOneToOne;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="first_entity")
 */
class FirstRelatedEntity
{
    /**
     * @var SecondRelatedEntity
     * @Id
     * @OneToOne(targetEntity="SecondRelatedEntity", fetch="EAGER")
     * @JoinColumn(name="second_entity_id", referencedColumnName="id")
     */
    public $secondEntity;

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
