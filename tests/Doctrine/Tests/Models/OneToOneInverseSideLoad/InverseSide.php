<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\OneToOneInverseSideLoad;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity()
 * @Table(name="one_to_one_inverse_side_load_inverse")
 */
class InverseSide
{
    /**
     * @var string
     * @Id()
     * @Column(type="string")
     * @GeneratedValue(strategy="NONE")
     */
    public $id;

    /**
     * @var OwningSide
     * @OneToOne(targetEntity=OwningSide::class, mappedBy="inverse")
     */
    public $owning;
}
