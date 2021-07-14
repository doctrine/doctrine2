<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\MixedToOneIdentity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;

/** @Entity */
class CompositeToOneKeyState
{
    /**
     * @var string
     * @Id
     * @Column(type="string")
     * @GeneratedValue(strategy="NONE")
     */
    public $state;

    /**
     * @var Country
     * @Id
     * @ManyToOne(targetEntity="Country", cascade={"MERGE"})
     * @JoinColumn(referencedColumnName="country")
     */
    public $country;
}
