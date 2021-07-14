<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Issue5989;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="issue5989_employees")
 */
class Issue5989Employee extends Issue5989Person
{
    /**
     * @Column(type="simple_array", nullable=true)
     * @var array
     */
    public $tags;
}
