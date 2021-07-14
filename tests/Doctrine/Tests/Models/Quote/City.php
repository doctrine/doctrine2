<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Quote;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="`quote-city`")
 */
class City
{
    /**
     * @var int
     * @Id
     * @GeneratedValue
     * @Column(type="integer", name="`city-id`")
     */
    public $id;

    /**
     * @var string
     * @Column(name="`city-name`")
     */
    public $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
