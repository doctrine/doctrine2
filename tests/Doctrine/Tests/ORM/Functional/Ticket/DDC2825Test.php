<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Tests\Models\DDC2825\ExplicitSchemaAndTable;
use Doctrine\Tests\Models\DDC2825\SchemaAndTableInTableName;
use Doctrine\Tests\OrmFunctionalTestCase;

use function sprintf;

/**
 * This class makes tests on the correct use of a database schema when entities are stored
 *
 * @group DDC-2825
 */
class DDC2825Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $platform = $this->_em->getConnection()->getDatabasePlatform();

        if (! $platform->supportsSchemas() && ! $platform->canEmulateSchemas()) {
            $this->markTestSkipped('This test is only useful for databases that support schemas or can emulate them.');
        }
    }

    /**
     * @dataProvider getTestedClasses
     */
    public function testClassSchemaMappingsValidity(string $className, string $expectedSchemaName, string $expectedTableName): void
    {
        $classMetadata   = $this->_em->getClassMetadata($className);
        $platform        = $this->_em->getConnection()->getDatabasePlatform();
        $quotedTableName = $this->_em->getConfiguration()->getQuoteStrategy()->getTableName($classMetadata, $platform);

        // Check if table name and schema properties are defined in the class metadata
        $this->assertEquals($expectedTableName, $classMetadata->table['name']);
        $this->assertEquals($expectedSchemaName, $classMetadata->table['schema']);

        if ($this->_em->getConnection()->getDatabasePlatform()->supportsSchemas()) {
            $fullTableName = sprintf('%s.%s', $expectedSchemaName, $expectedTableName);
        } else {
            $fullTableName = sprintf('%s__%s', $expectedSchemaName, $expectedTableName);
        }

        $this->assertEquals($fullTableName, $quotedTableName);

        // Checks sequence name validity
        $this->assertEquals(
            $fullTableName . '_' . $classMetadata->getSingleIdentifierColumnName() . '_seq',
            $classMetadata->getSequenceName($platform)
        );
    }

    /**
     * @dataProvider getTestedClasses
     */
    public function testPersistenceOfEntityWithSchemaMapping(string $className): void
    {
        try {
            $this->_schemaTool->createSchema([$this->_em->getClassMetadata($className)]);
        } catch (ToolsException $e) {
            // table already exists
        }

        $this->_em->persist(new $className());
        $this->_em->flush();
        $this->_em->clear();

        $this->assertCount(1, $this->_em->getRepository($className)->findAll());
    }

    /**
     * Data provider
     *
     * @return string[][]
     */
    public function getTestedClasses(): array
    {
        return [
            [ExplicitSchemaAndTable::class, 'explicit_schema', 'explicit_table'],
            [SchemaAndTableInTableName::class, 'implicit_schema', 'implicit_table'],
            [DDC2825ClassWithImplicitlyDefinedSchemaAndQuotedTableName::class, 'myschema', 'order'],
        ];
    }
}

/**
 * @Entity
 * @Table(name="myschema.order")
 */
#[ORM\Entity, ORM\Table(name: 'myschema.order')]
class DDC2825ClassWithImplicitlyDefinedSchemaAndQuotedTableName
{
    /**
     * @Id @GeneratedValue
     * @Column(type="integer")
     * @var int
     */
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public $id;
}
