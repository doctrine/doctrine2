<?php
// phpcs:ignoreFile
namespace Doctrine\Tests\Models\PropertyHooks;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'property_hooks_hooked_identifier')]
class HookedIdentifier
{
    #[Id, GeneratedValue, Column(type: Types::INTEGER)]
    public int $id {
        get => $this->id;
        set => $this->id = $value;
    }
}
