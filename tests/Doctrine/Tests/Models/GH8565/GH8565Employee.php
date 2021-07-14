<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\GH8565;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\DbalTypes\GH8565EmployeePayloadType;

/**
 * @Entity
 * @Table(name="gh8565_employees")
 */
class GH8565Employee extends GH8565Person
{
    /**
     * @Column(type="GH8565EmployeePayloadType", nullable=false)
     * @var GH8565EmployeePayloadType
     */
    public $type;
}
