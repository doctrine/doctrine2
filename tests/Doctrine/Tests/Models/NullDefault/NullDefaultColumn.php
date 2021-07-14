<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\NullDefault;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;

/** @Entity */
class NullDefaultColumn
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var mixed
     * @Column(options={"default":NULL})
     */
    public $nullDefault;
}
