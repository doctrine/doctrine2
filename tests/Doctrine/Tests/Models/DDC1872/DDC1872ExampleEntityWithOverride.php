<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC1872;

use Doctrine\ORM\Mapping\AssociationOverride;
use Doctrine\ORM\Mapping\AssociationOverrides;
use Doctrine\ORM\Mapping\AttributeOverride;
use Doctrine\ORM\Mapping\AttributeOverrides;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;

/**
 * @Entity
 * @AttributeOverrides({
 * @AttributeOverride(name="foo",
 *          column=@Column(
 *              name     = "foo_overridden",
 *              type     = "integer",
 *              length   = 140,
 *              nullable = false,
 *              unique   = false
 *          )
 *      )
 * })
 * @AssociationOverrides({
 * @AssociationOverride(name="bar",
 *          joinColumns=@JoinColumn(
 *              name="example_entity_overridden_bar_id", referencedColumnName="id"
 *          )
 *      )
 * })
 */
class DDC1872ExampleEntityWithOverride
{
    use DDC1872ExampleTrait;
}
