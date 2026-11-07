<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Persisters;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Expr\Comparison;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Types\Type as DBALType;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\DefaultQuoteStrategy;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\Tests\DbalTypes\NegativeToPositiveType;
use Doctrine\Tests\DbalTypes\UpperCaseStringType;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Models\CustomType\CustomTypeChild;
use Doctrine\Tests\Models\CustomType\CustomTypeParent;
use Doctrine\Tests\Models\CustomType\CustomTypeQuotedUpperCase;
use Doctrine\Tests\Models\Generic\NonAlphaColumnsEntity;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;

use function array_slice;
use function defined;
use function enum_exists;

class BasicEntityPersisterTypeValueSqlTest extends OrmTestCase
{
    protected BasicEntityPersister $persister;
    protected EntityManagerMock $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (DBALType::hasType('negative_to_positive')) {
            DBALType::overrideType('negative_to_positive', NegativeToPositiveType::class);
        } else {
            DBALType::addType('negative_to_positive', NegativeToPositiveType::class);
        }

        if (DBALType::hasType('upper_case_string')) {
            DBALType::overrideType('upper_case_string', UpperCaseStringType::class);
        } else {
            DBALType::addType('upper_case_string', UpperCaseStringType::class);
        }

        $this->entityManager = $this->getTestEntityManager();

        $this->persister = new BasicEntityPersister($this->entityManager, $this->entityManager->getClassMetadata(CustomTypeParent::class));
    }

    public function testGetInsertSQLUsesTypeValuesSQL(): void
    {
        $method = new ReflectionMethod($this->persister, 'getInsertSQL');
        $sql    = $method->invoke($this->persister);

        self::assertEquals('INSERT INTO customtype_parents (customInteger, child_id) VALUES (ABS(?), ?)', $sql);
    }

    #[Group('GH-7460')]
    #[Group('GH-8191')]
    public function testGetInsertSQLUsesTypeValuesSQLForQuotedColumns(): void
    {
        $persister = new BasicEntityPersister($this->entityManager, $this->entityManager->getClassMetadata(CustomTypeQuotedUpperCase::class));

        self::assertSame('INSERT INTO customtype_quoted_uppercases ("key") VALUES (UPPER(?))', $persister->getInsertSQL());
    }

    #[Group('GH-8191')]
    public function testGetInsertSQLUsesTypeValuesSQLWithQuoteStrategyQuotingAllColumns(): void
    {
        $this->entityManager->getConfiguration()->setQuoteStrategy(new class extends DefaultQuoteStrategy {
            public function getColumnName(string $fieldName, ClassMetadata $class, AbstractPlatform $platform): string
            {
                return $platform->quoteSingleIdentifier($class->fieldMappings[$fieldName]->columnName);
            }
        });

        $persister = new BasicEntityPersister($this->entityManager, $this->entityManager->getClassMetadata(CustomTypeParent::class));

        self::assertSame('INSERT INTO customtype_parents ("customInteger", child_id) VALUES (ABS(?), ?)', $persister->getInsertSQL());
    }

    public function testUpdateUsesTypeValuesSQL(): void
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')
            ->willReturn($this->createStub(Driver\Connection::class));

        $platform = $this->getMockBuilder(AbstractPlatform::class)
            ->setConstructorArgs(enum_exists(UnquotedIdentifierFolding::class) ? [UnquotedIdentifierFolding::UPPER] : [])
            ->getMock();
        $platform->method('supportsIdentityColumns')
            ->willReturn(true);

        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([[], $driver])
            ->onlyMethods(['executeStatement', 'getDatabasePlatform'])
            ->getMock();
        $connection->method('getDatabasePlatform')
            ->willReturn($platform);

        $child = new CustomTypeChild();

        $parent                = new CustomTypeParent();
        $parent->customInteger = 1;
        $parent->child         = $child;

        $entityManager = $this->createTestEntityManagerWithConnection($connection);

        $entityManager->getUnitOfWork()->registerManaged($parent, ['id' => 1], ['customInteger' => 0, 'child' => null]);
        $entityManager->getUnitOfWork()->registerManaged($child, ['id' => 1], []);

        $entityManager->getUnitOfWork()->propertyChanged($parent, 'customInteger', 0, 1);
        $entityManager->getUnitOfWork()->propertyChanged($parent, 'child', null, $child);

        $persister = new BasicEntityPersister($entityManager, $entityManager->getClassMetadata(CustomTypeParent::class));

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with('UPDATE customtype_parents SET customInteger = ABS(?), child_id = ? WHERE id = ?');

        $persister->update($parent);
    }

    public function testGetSelectConditionSQLUsesTypeValuesSQL(): void
    {
        $method = new ReflectionMethod($this->persister, 'getSelectConditionSQL');
        $sql    = $method->invoke($this->persister, ['customInteger' => 1, 'child' => 1]);

        self::assertEquals('t0.customInteger = ABS(?) AND t0.child_id = ?', $sql);
    }

    #[Group('DDC-1719')]
    public function testStripNonAlphanumericCharactersFromSelectColumnListSQL(): void
    {
        $persister = new BasicEntityPersister($this->entityManager, $this->entityManager->getClassMetadata(NonAlphaColumnsEntity::class));
        $method    = new ReflectionMethod($persister, 'getSelectColumnsSQL');

        self::assertEquals('t0."simple-entity-id" AS simpleentityid_1, t0."simple-entity-value" AS simpleentityvalue_2', $method->invoke($persister));
    }

    #[Group('DDC-2073')]
    public function testSelectConditionStatementIsNull(): void
    {
        $associationMapping = new OneToManyAssociationMapping('foo', 'bar', 'baz');
        $statement          = $this->persister->getSelectConditionStatementSQL('test', null, $associationMapping, Comparison::IS);
        self::assertEquals('test IS NULL', $statement);
    }

    public function testSelectConditionStatementEqNull(): void
    {
        $associationMapping = new OneToManyAssociationMapping('foo', 'bar', 'baz');
        $statement          = $this->persister->getSelectConditionStatementSQL('test', null, $associationMapping, Comparison::EQ);
        self::assertEquals('test IS NULL', $statement);
    }

    public function testSelectConditionStatementNeqNull(): void
    {
        $associationMapping = new OneToManyAssociationMapping('foo', 'bar', 'baz');
        $statement          = $this->persister->getSelectConditionStatementSQL(
            'test',
            null,
            $associationMapping,
            Comparison::NEQ,
        );
        self::assertEquals('test IS NOT NULL', $statement);
    }

    #[Group('DDC-3056')]
    #[Group('GH12254')]
    public function testSelectConditionStatementWithMultipleValuesContainingNull(): void
    {
        self::assertEquals(
            't0.id IS NULL',
            $this->persister->getSelectConditionStatementSQL('id', [null]),
        );

        self::assertEquals(
            '(t0.id IN (?) OR t0.id IS NULL)',
            $this->persister->getSelectConditionStatementSQL('id', [null, 123]),
        );

        self::assertEquals(
            '(t0.id IN (?) OR t0.id IS NULL)',
            $this->persister->getSelectConditionStatementSQL('id', [123, null]),
        );

        self::assertEquals(
            '(t0.id IN (?, ?) OR t0.id IS NULL)',
            $this->persister->getSelectConditionStatementSQL('id', [123, null, 234]),
        );

        self::assertEquals(
            '1=0',
            $this->persister->getSelectConditionStatementSQL('id', []),
        );
    }

    public function testCountCondition(): void
    {
        $persister = new BasicEntityPersister($this->entityManager, $this->entityManager->getClassMetadata(NonAlphaColumnsEntity::class));

        // Using a criteria as array
        $statement = $persister->getCountSQL(['value' => 'bar']);
        self::assertEquals('SELECT COUNT(*) FROM "not-a-simple-entity" t0 WHERE t0."simple-entity-value" = ?', $statement);

        // Using a criteria object
        $criteria  = (defined(Criteria::class . '::ASC') ? Criteria::create(true) : Criteria::create())->where(Criteria::expr()->eq('value', 'bar'));
        $statement = $persister->getCountSQL($criteria);
        self::assertEquals('SELECT COUNT(*) FROM "not-a-simple-entity" t0 WHERE t0."simple-entity-value" = ?', $statement);
    }

    public function testCountEntities(): void
    {
        self::assertEquals(0, $this->persister->count());
    }

    public function testDeleteManyToManyUsesTypeValuesSQL(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([[], $this->createStub(Driver::class)])
            ->onlyMethods(['delete', 'getDatabasePlatform'])
            ->getMock();
        $connection->method('getDatabasePlatform')
            ->willReturn($this->createStub(AbstractPlatform::class));

        $entityManager = $this->createTestEntityManagerWithConnection($connection);

        $persister = new BasicEntityPersister($entityManager, $this->entityManager->getClassMetadata(CustomTypeParent::class));

        $friend = new CustomTypeParent();
        $parent = new CustomTypeParent();
        $parent->addMyFriend($friend);

        $entityManager->getUnitOfWork()->registerManaged($parent, ['id' => 1], []);
        $entityManager->getUnitOfWork()->registerManaged($friend, ['id' => 2], []);

        $deleteCalls = [];

        $connection->method('delete')
            ->willReturnCallback(static function (...$args) use (&$deleteCalls): int {
                $deleteCalls[] = $args;

                return 1;
            });

        $persister->delete($parent);

        self::assertSame([
            [
                'customtype_parent_friends',
                ['friend_customtypeparent_id' => 1],
                ['integer'],
            ],
            [
                'customtype_parent_friends',
                ['customtypeparent_id' => 1],
                ['integer'],
            ],
        ], array_slice($deleteCalls, 0, 2));
    }
}
