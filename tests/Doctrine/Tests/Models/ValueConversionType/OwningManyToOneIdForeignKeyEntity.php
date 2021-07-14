<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="vct_owning_manytoone_foreignkey")
 */
class OwningManyToOneIdForeignKeyEntity
{
    /**
     * @var associatedEntities
     * @Id
     * @ManyToOne(targetEntity=AuxiliaryEntity::class, inversedBy="associatedEntities")
     * @JoinColumn(name="associated_id", referencedColumnName="id4")
     */
    public $associatedEntity;
}
