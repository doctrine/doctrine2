<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Quote;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;

/**
 * @Entity
 */
class FullAddress extends Address
{
    /**
     * @var City
     * @OneToOne(targetEntity=City::class, cascade={"persist"})
     * @JoinColumn(name="`city-id`", referencedColumnName="`city-id`")
     */
    public $city;
}
