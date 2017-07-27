<?php

declare(strict_types=1);

namespace Doctrine\Tests\Models\DDC889;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Annotation as ORM;
use Doctrine\ORM\Mapping;

/**
 * @ORM\MappedSuperclass
 */
class DDC889SuperClass
{
    /** @ORM\Column() */
    protected $name;

    public static function loadMetadata(Mapping\ClassMetadata $metadata)
    {
        $fieldMetadata = new Mapping\FieldMetadata('name');
        $fieldMetadata->setType(Type::getType('string'));
        $fieldMetadata->setIdentifierGeneratorType(Mapping\GeneratorType::NONE);

        $metadata->addProperty($fieldMetadata);
        $metadata->isMappedSuperclass = true;
    }
}
