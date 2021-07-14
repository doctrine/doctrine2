<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\Forum;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 */
class ForumAdministrator extends ForumUser
{
    /**
     * @var int
     * @Column(type="integer", name="access_level")
     */
    public $accessLevel;
}
