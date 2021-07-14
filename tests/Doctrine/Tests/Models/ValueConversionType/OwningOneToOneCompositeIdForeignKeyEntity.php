<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinColumns;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;

/**
 * @Entity
 * @Table(
 *     name="vct_owning_onetoone_compositeid_foreignkey",
 *     uniqueConstraints={
 *         @UniqueConstraint(name="associated_entity_uniq", columns={"associated_id", "associated_foreign_id"})
 *     }
 * )
 */
class OwningOneToOneCompositeIdForeignKeyEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id2;

    /**
     * @var InversedOneToOneCompositeIdForeignKeyEntity
     * @OneToOne(targetEntity="InversedOneToOneCompositeIdForeignKeyEntity", inversedBy="associatedEntity")
     * @JoinColumns({
     *     @JoinColumn(name="associated_id", referencedColumnName="id1"),
     *     @JoinColumn(name="associated_foreign_id", referencedColumnName="foreign_id")
     * })
     */
    public $associatedEntity;
}
