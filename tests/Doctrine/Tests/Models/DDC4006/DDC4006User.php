<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC4006;

use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 */
class DDC4006User
{
    /**
     * @var DDC4006UserId
     * @Embedded(class="DDC4006UserId")
     */
    private $id;
}
