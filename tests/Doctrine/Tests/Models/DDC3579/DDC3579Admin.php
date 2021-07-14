<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC3579;

use Doctrine\ORM\Mapping\AssociationOverride;
use Doctrine\ORM\Mapping\AssociationOverrides;
use Doctrine\ORM\Mapping\Entity;

/**
 * @Entity
 * @AssociationOverrides({
 *      @AssociationOverride(
 *          name="groups",
 *          inversedBy="admins"
 *      )
 * })
 */
class DDC3579Admin extends DDC3579User
{
    public static function loadMetadata($metadata): void
    {
        $metadata->setAssociationOverride('groups', ['inversedBy' => 'admins']);
    }
}
