<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="vct_owning_manytoone")
 */
class OwningManyToOneEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id2;

    /**
     * @var InversedOneToManyEntity
     * @ManyToOne(targetEntity="InversedOneToManyEntity", inversedBy="associatedEntities")
     * @JoinColumn(name="associated_id", referencedColumnName="id1")
     */
    public $associatedEntity;
}
