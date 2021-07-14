<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_filter;
use function current;
use function method_exists;
use function sprintf;
use function strpos;

/** @group GH7875 */
final class GH7875Test extends OrmFunctionalTestCase
{
    /** @after */
    public function cleanUpSchema(): void
    {
        $connection = $this->_em->getConnection();

        $connection->exec('DROP TABLE IF EXISTS gh7875_my_entity');
        $connection->exec('DROP TABLE IF EXISTS gh7875_my_other_entity');

        if ($connection->getDatabasePlatform() instanceof PostgreSqlPlatform) {
            $connection->exec('DROP SEQUENCE IF EXISTS gh7875_my_entity_id_seq');
            $connection->exec('DROP SEQUENCE IF EXISTS gh7875_my_other_entity_id_seq');
        }
    }

    /**
     * @param string[] $sqls
     *
     * @return string[]
     */
    private function filterCreateTable(array $sqls, string $tableName): array
    {
        return array_filter($sqls, static function (string $sql) use ($tableName): bool {
            return strpos($sql, sprintf('CREATE TABLE %s (', $tableName)) === 0;
        });
    }

    public function testUpdateSchemaSql(): void
    {
        $classes = [$this->_em->getClassMetadata(GH7875MyEntity::class)];

        $tool = new SchemaTool($this->_em);
        $sqls = $this->filterCreateTable($tool->getUpdateSchemaSql($classes), 'gh7875_my_entity');

        self::assertCount(1, $sqls);

        $this->_em->getConnection()->exec(current($sqls));

        $sqls = array_filter($tool->getUpdateSchemaSql($classes), static function (string $sql): bool {
            return strpos($sql, ' gh7875_my_entity ') !== false;
        });

        self::assertSame([], $sqls);

        $classes[] = $this->_em->getClassMetadata(GH7875MyOtherEntity::class);

        $sqls = $tool->getUpdateSchemaSql($classes);

        self::assertCount(0, $this->filterCreateTable($sqls, 'gh7875_my_entity'));
        self::assertCount(1, $this->filterCreateTable($sqls, 'gh7875_my_other_entity'));
    }

    /**
     * @return array<array<string|callable|null>>
     */
    public function provideUpdateSchemaSqlWithSchemaAssetFilter(): array
    {
        return [
            ['/^(?!my_enti)/', null],
            [
                null,
                static function ($assetName): bool {
                    return $assetName !== 'gh7875_my_entity';
                },
            ],
        ];
    }

    /** @dataProvider provideUpdateSchemaSqlWithSchemaAssetFilter */
    public function testUpdateSchemaSqlWithSchemaAssetFilter(?string $filterRegex, ?callable $filterCallback): void
    {
        if ($filterRegex && ! method_exists(Configuration::class, 'setFilterSchemaAssetsExpression')) {
            self::markTestSkipped(sprintf('Test require %s::setFilterSchemaAssetsExpression method', Configuration::class));
        }

        $classes = [$this->_em->getClassMetadata(GH7875MyEntity::class)];

        $tool = new SchemaTool($this->_em);
        $tool->createSchema($classes);

        $config = $this->_em->getConnection()->getConfiguration();
        if ($filterRegex) {
            $config->setFilterSchemaAssetsExpression($filterRegex);
        } else {
            $config->setSchemaAssetsFilter($filterCallback);
        }

        $previousFilter = $config->getSchemaAssetsFilter();

        $sqls = $tool->getUpdateSchemaSql($classes);
        $sqls = array_filter($sqls, static function (string $sql): bool {
            return strpos($sql, ' gh7875_my_entity ') !== false;
        });

        self::assertCount(0, $sqls);
        self::assertSame($previousFilter, $config->getSchemaAssetsFilter());
    }
}

/**
 * @Entity
 * @Table(name="gh7875_my_entity")
 */
class GH7875MyEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}

/**
 * @Entity
 * @Table(name="gh7875_my_other_entity")
 */
class GH7875MyOtherEntity
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;
}
