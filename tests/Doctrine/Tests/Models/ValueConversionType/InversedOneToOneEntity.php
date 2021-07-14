<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ValueConversionType;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="vct_inversed_onetoone")
 */
class InversedOneToOneEntity
{
    /**
     * @var string
     * @Column(type="rot13")
     * @Id
     */
    public $id1;

    /**
     * @var string
     * @Column(type="string", name="some_property")
     */
    public $someProperty;

    /**
     * @var OwningOneToOneEntity
     * @OneToOne(targetEntity="OwningOneToOneEntity", mappedBy="associatedEntity")
     */
    public $associatedEntity;
}
