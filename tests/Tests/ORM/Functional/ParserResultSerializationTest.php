<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Closure;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Exec\FinalizedSelectExecutor;
use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\OrmFunctionalTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\VarExporter\VarExporter;

use function serialize;
use function unserialize;

class ParserResultSerializationTest extends OrmFunctionalTestCase
{
    use VerifyDeprecations;

    protected function setUp(): void
    {
        $this->useModelSet('company');

        parent::setUp();
    }

    /** @param Closure(ParserResult): ParserResult $toSerializedAndBack */
    #[DataProvider('provideToSerializedAndBack')]
    public function testSerializeParserResultForQueryWithSqlOutputWalker(Closure $toSerializedAndBack): void
    {
        $query = $this->_em
            ->createQuery('SELECT u FROM Doctrine\Tests\Models\Company\CompanyEmployee u WHERE u.name = :name');

        $parserResult = self::parseQuery($query);
        $unserialized = $toSerializedAndBack($parserResult);

        $this->assertInstanceOf(ParserResult::class, $unserialized);
        $this->assertInstanceOf(ResultSetMapping::class, $unserialized->getResultSetMapping());
        $this->assertEquals(['name' => [0]], $unserialized->getParameterMappings());
        $this->assertNotNull($unserialized->prepareSqlExecutor($query));
    }

    /** @return Generator<string, array{Closure(ParserResult): ParserResult}> */
    public static function provideToSerializedAndBack(): Generator
    {
        yield 'native serialization function' => [
            static function (ParserResult $parserResult): ParserResult {
                return unserialize(serialize($parserResult));
            },
        ];

        yield 'symfony/var-exporter' => [
            static function (ParserResult $parserResult): ParserResult {
                return eval('return ' . VarExporter::export($parserResult) . ';');
            },
        ];
    }

    public function testSymfony44ProvidedData(): void
    {
        $sqlExecutor      = new FinalizedSelectExecutor('test');
        $sqlFinalizer     = new PreparedExecutorFinalizer($sqlExecutor);
        $resultSetMapping = $this->createStub(ResultSetMapping::class);

        $parserResult = new ParserResult();
        $parserResult->setSqlFinalizer($sqlFinalizer);
        $parserResult->setResultSetMapping($resultSetMapping);
        $parserResult->addParameterMapping('name', 0);

        $exported     = VarExporter::export($parserResult);
        $unserialized = eval('return ' . $exported . ';');

        $this->assertInstanceOf(ParserResult::class, $unserialized);
        $this->assertInstanceOf(ResultSetMapping::class, $unserialized->getResultSetMapping());
        $this->assertEquals(['name' => [0]], $unserialized->getParameterMappings());
        $this->assertEquals($sqlExecutor, $unserialized->prepareSqlExecutor($this->createStub(Query::class)));
    }

    private static function parseQuery(Query $query): ParserResult
    {
        $r = new ReflectionMethod($query, 'parse');

        return $r->invoke($query);
    }
}
