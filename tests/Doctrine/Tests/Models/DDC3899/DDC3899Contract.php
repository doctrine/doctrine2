<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3899;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="dc3899_contracts")
 * @InheritanceType("SINGLE_TABLE")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({
 *     "fix"       = "DDC3899FixContract",
 *     "flexible"  = "DDC3899FlexContract"
 * })
 */
abstract class DDC3899Contract
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var bool
     * @Column(type="boolean")
     */
    public $completed = false;

    /**
     * @var DDC3899User
     * @ManyToOne(targetEntity="DDC3899User", inversedBy="contract")
     */
    public $user;
}
