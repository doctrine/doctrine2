<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\UniDirectionalManyToMany;

use Doctrine\ORM\Annotation as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="uni_directional_many_to_many_inverse")
 */
class Inverse
{
    /**
     * @ORM\Column(type="string")
     * @ORM\Id
     */
    public $id;

    public function __construct()
    {
        $this->id = \uniqid(self::class, true);
    }
}
