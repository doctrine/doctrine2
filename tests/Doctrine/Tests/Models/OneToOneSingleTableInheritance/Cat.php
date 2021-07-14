<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\OneToOneSingleTableInheritance;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\OneToOne;

/** @Entity */
class Cat extends Pet
{
    /**
     * @var LitterBox
     * @OneToOne(targetEntity="LitterBox")
     */
    public $litterBox;
}
