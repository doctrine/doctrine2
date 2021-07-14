<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC2825;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/** @Entity @Table(name="explicit_table", schema="explicit_schema") */
#[ORM\Entity, ORM\Table(name: 'explicit_table', schema: 'explicit_schema')]
class ExplicitSchemaAndTable
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'AUTO')]
    public $id;
}
