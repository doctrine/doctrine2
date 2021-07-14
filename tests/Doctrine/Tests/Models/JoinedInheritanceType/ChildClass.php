<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\JoinedInheritanceType;

use Doctrine\ORM\Mapping\MappedSuperclass;

/**
 * @MappedSuperclass
 */
abstract class ChildClass extends RootClass
{
}
