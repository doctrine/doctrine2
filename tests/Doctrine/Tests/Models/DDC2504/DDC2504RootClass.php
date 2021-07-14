<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC2504;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *     "root"  = "DDC2504RootClass",
 *     "child" = "DDC2504ChildClass"
 * })
 */
class DDC2504RootClass
{
    /**
     * @var int
     * @Column(type="integer")
     * @Id @GeneratedValue
     */
    public $id;

    /**
     * @var DDC2504OtherClass
     * @ManyToOne(targetEntity="DDC2504OtherClass", inversedBy="childClasses")
     */
    public $other;
}
