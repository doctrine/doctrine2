<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC2825;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * Quoted column name to check that sequence names are
 * correctly handled
 *
 * @Entity @Table(name="implicit_schema.implicit_table")
 */
#[ORM\Entity, ORM\Table(name: 'implicit_schema.implicit_table')]
class SchemaAndTableInTableName
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
