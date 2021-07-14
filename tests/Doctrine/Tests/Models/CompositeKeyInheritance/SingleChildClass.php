<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\CompositeKeyInheritance;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;

/**
 * @Entity
 */
class SingleChildClass extends SingleRootClass
{
    /**
     * @var string
     * @Column(type="string")
     */
    public $extension = 'ext';

    /**
     * @var string
     * @Column(type="string")
     * @Id
     */
    private $additionalId = 'additional';
}
