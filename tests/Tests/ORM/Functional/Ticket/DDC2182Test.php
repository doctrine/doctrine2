<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\ORM\Tools\SchemaAssertions;
use Doctrine\Tests\OrmFunctionalTestCase;

class DDC2182Test extends OrmFunctionalTestCase
{
    use SchemaAssertions;

    public function testPassColumnOptionsToJoinColumns(): void
    {
        if (! $this->_em->getConnection()->getDatabasePlatform() instanceof MySQLPlatform) {
            self::markTestSkipped('This test is useful for all databases, but designed only for mysql.');
        }

        $schema = $this->_schemaTool->getSchemaFromMetadata(
            [
                $this->_em->getClassMetadata(DDC2182OptionParent::class),
                $this->_em->getClassMetadata(DDC2182OptionChild::class),
            ],
        );

        $parent = $schema->getTable('DDC2182OptionParent');
        self::assertTableColumns($parent, [
            'id' => ['type' => 'integer', 'nullable' => false, 'unsigned' => true],
        ]);
        self::assertSame(['id'], self::primaryKeyColumns($parent));

        $child = $schema->getTable('DDC2182OptionChild');
        self::assertTableColumns($child, [
            'id'        => ['type' => 'string', 'nullable' => false, 'length' => 255],
            'parent_id' => ['type' => 'integer', 'nullable' => true, 'unsigned' => true],
        ]);
        self::assertSame(['id'], self::primaryKeyColumns($child));
        self::assertTableIndex($child, 'IDX_B314D4AD727ACA70', ['parent_id']);
        self::assertCount(1, $child->getForeignKeys());
        self::assertTableForeignKey($child, ['parent_id'], 'DDC2182OptionParent', ['id']);
    }
}

#[Table]
#[Entity]
class DDC2182OptionParent
{
    #[Id]
    #[Column(type: 'integer', options: ['unsigned' => true])]
    private int $id;
}

#[Table]
#[Entity]
class DDC2182OptionChild
{
    #[Id]
    #[Column]
    private string $id;

    #[ManyToOne(targetEntity: 'DDC2182OptionParent')]
    #[JoinColumn(referencedColumnName: 'id')]
    private DDC2182OptionParent $parent;
}
