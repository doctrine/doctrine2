<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Index as DbalIndex;
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

        self::assertTableEquals(
            self::table(
                'DDC2182OptionParent',
                [
                    self::column('id', 'integer', ['unsigned' => true, 'platformOptions' => []]),
                ],
                primaryKey: ['id'],
            ),
            $schema->getTable('DDC2182OptionParent'),
        );

        self::assertTableEquals(
            self::table(
                'DDC2182OptionChild',
                [
                    self::column('id', 'string', ['length' => 255]),
                    self::joinColumn('parent_id', 'integer', ['notnull' => false, 'unsigned' => true]),
                ],
                primaryKey: ['id'],
                indexes: [
                    new DbalIndex('IDX_B314D4AD727ACA70', ['parent_id']),
                ],
                foreignKeys: [
                    self::foreignKey(['parent_id'], 'DDC2182OptionParent', ['id']),
                ],
            ),
            $schema->getTable('DDC2182OptionChild'),
        );
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
