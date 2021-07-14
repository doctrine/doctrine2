<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3899;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 */
class DDC3899FlexContract extends DDC3899Contract
{
    /**
     * @var int
     * @Column(type="integer")
     */
    public $hoursWorked = 0;

    /**
     * @var int
     * @Column(type="integer")
     */
    public $pricePerHour = 0;
}
