<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Generic;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="serialize_model")
 */
class SerializationModel
{
    /**
     * @var int
     * @Id @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var mixed[]
     * @Column(name="the_array", type="array", nullable=true)
     */
    public $array;

    /**
     * @var object
     * @Column(name="the_obj", type="object", nullable=true)
     */
    public $object;
}
