<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\UniDirectionalManyToMany;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Annotation as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="uni_directional_many_to_many_owning")
 */
class Owning
{
    /**
     * @ORM\Column(type="string")
     * @ORM\Id
     */
    public $id;

    /**
     * @var Inverse[]|Collection
     *
     * @ORM\ManyToMany(targetEntity=Inverse::class)
     */
    public $inverse;

    public function __construct()
    {
        $this->id      = \uniqid(self::class, true);
        $this->inverse = new ArrayCollection();
    }
}
