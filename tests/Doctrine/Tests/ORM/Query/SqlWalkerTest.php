<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query;

use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query;
use Doctrine\Tests\OrmTestCase;
use Doctrine\ORM\Query\ParserResult;

/**
 * Tests for {@see \Doctrine\ORM\Query\SqlWalker}
 *
 * @covers \Doctrine\ORM\Query\SqlWalker
 */
class SqlWalkerTest extends OrmTestCase
{
    /**
     * @var SqlWalker
     */
    private $sqlWalker;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $this->sqlWalker = new SqlWalker(new Query($this->getTestEntityManager()), new ParserResult(), []);
    }

    /**
     * @dataProvider getColumnNamesAndSqlAliases
     */
    public function testGetSQLTableAlias($tableName, $expectedAlias)
    {
        self::assertSame($expectedAlias, $this->sqlWalker->getSQLTableAlias($tableName));
    }

    /**
     * @dataProvider getColumnNamesAndSqlAliases
     */
    public function testGetSQLTableAliasIsSameForMultipleCalls($tableName)
    {
        self::assertSame(
            $this->sqlWalker->getSQLTableAlias($tableName),
            $this->sqlWalker->getSQLTableAlias($tableName)
        );
    }

    /**
     * @private data provider
     *
     * @return string[][]
     */
    public function getColumnNamesAndSqlAliases()
    {
        return [
            ['aaaaa', 't0'],
            ['table', 't0'],
            ['çtable', 't0'],
        ];
    }
}
