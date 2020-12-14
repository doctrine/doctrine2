<?php

namespace Doctrine\ORM\Mapping;

use Doctrine\ORM\Annotation\AssociationOverride as NewAssociationOverride;
use function class_exists;
use function trigger_error;

class_exists('Doctrine\ORM\Annotation\AssociationOverride');

trigger_error('Doctrine\ORM\Annotation\AssociationOverride', \E_USER_DEPRECATED);

class AssociationOverride extends NewAssociationOverride
{
}
