<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="vct_owning_onetoone")
 */
class OwningOneToOneEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id2;

    /**
     * @var InversedOneToOneEntity
     * @OneToOne(targetEntity="InversedOneToOneEntity", inversedBy="associatedEntity")
     * @JoinColumn(name="associated_id", referencedColumnName="id1")
     */
    public $associatedEntity;
}
