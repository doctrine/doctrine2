<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\ManyToManyPersister;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * @Entity
 * @Table(name="manytomanypersister_other_parent")
 */
class OtherParentClass
{
    /**
     * @Id
     * @Column(name="id", type="integer")
     * @var int
     */
    public $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}
