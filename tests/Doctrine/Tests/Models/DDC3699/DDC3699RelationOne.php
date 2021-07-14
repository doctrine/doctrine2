<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3699;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="ddc3699_relation_one")
 */
class DDC3699RelationOne
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var DDC3699Child
     * @OneToOne(targetEntity="DDC3699Child", mappedBy="oneRelation")
     */
    public $child;
}
