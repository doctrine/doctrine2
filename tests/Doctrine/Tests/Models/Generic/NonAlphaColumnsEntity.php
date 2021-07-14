<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Generic;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="`not-a-simple-entity`")
 */
class NonAlphaColumnsEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer", name="`simple-entity-id`")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", name="`simple-entity-value`")
     */
    public $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
