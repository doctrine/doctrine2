<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Utility;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Utility\PersisterHelper;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('GH-12634')]
class PersisterHelperTest extends OrmTestCase
{
    public function testFieldMappingOfTheColumnOfAField(): void
    {
        $em      = $this->getTestEntityManager();
        $mapping = PersisterHelper::getFieldMappingOfColumn(
            'label_col',
            $em->getClassMetadata(PersisterHelperEntity::class),
            $em,
        );

        self::assertSame('label', $mapping->fieldName);
        self::assertSame('string', $mapping->type);
        self::assertSame(20, $mapping->length);
    }

    public function testFieldMappingOfAJoinColumnIsTheOneOfTheReferencedField(): void
    {
        $em      = $this->getTestEntityManager();
        $class   = $em->getClassMetadata(PersisterHelperEntity::class);
        $mapping = PersisterHelper::getFieldMappingOfColumn('parent_id', $class, $em);

        self::assertSame('id', $mapping->fieldName);
        self::assertSame(36, $mapping->length);
        self::assertSame(['fixed' => true], $mapping->options);
        self::assertSame('string', PersisterHelper::getTypeOfColumn('parent_id', $class, $em));
    }

    public function testColumnThatCannotBeResolved(): void
    {
        $em = $this->getTestEntityManager();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Could not resolve type of column "unknown" of class "' . PersisterHelperEntity::class . '"',
        );

        PersisterHelper::getFieldMappingOfColumn('unknown', $em->getClassMetadata(PersisterHelperEntity::class), $em);
    }
}

#[Entity]
class PersisterHelperEntity
{
    #[Id]
    #[Column(name: 'id_col', type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;

    #[Column(name: 'label_col', type: 'string', length: 20)]
    public string $label;

    #[ManyToOne(targetEntity: self::class)]
    #[JoinColumn(name: 'parent_id', referencedColumnName: 'id_col')]
    public self|null $parent = null;
}
