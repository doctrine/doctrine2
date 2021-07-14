<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Cache;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table("cache_person")
 * @Cache("NONSTRICT_READ_WRITE")
 */
class Person
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer")
     */
    public $id;

    /**
     * @var string
     * @Column(unique=true)
     */
    public $name;

    /**
     * @var Address
     * @OneToOne(targetEntity="Address", mappedBy="person")
     */
    public $address;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
