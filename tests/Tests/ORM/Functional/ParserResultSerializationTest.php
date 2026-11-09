<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Closure;
use Doctrine\Deprecations\PHPUnit\VerifyDeprecations;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Exec\FinalizedSelectExecutor;
use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\Exec\SingleSelectExecutor;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\ParserResult;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Tests\OrmFunctionalTestCase;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use ReflectionMethod;
use Symfony\Component\VarExporter\VarExporter;

use function file_get_contents;
use function rtrim;
use function serialize;
use function sprintf;
use function strlen;
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
    #[IgnoreDeprecations]
    public function testSerializeParserResultForQueryWithSqlWalker(Closure $toSerializedAndBack): void
    {
        $query = $this->_em
            ->createQuery('SELECT u FROM Doctrine\Tests\Models\Company\CompanyEmployee u WHERE u.name = :name');

        // Use the (legacy) SqlWalker which directly puts an SqlExecutor instance into the parser result
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, Query\SqlWalker::class);

        $parserResult = self::parseQuery($query);
        $unserialized = $toSerializedAndBack($parserResult);

        $this->assertInstanceOf(ParserResult::class, $unserialized);
        $this->assertInstanceOf(ResultSetMapping::class, $unserialized->getResultSetMapping());
        $this->assertEquals(['name' => [0]], $unserialized->getParameterMappings());
        $this->assertNotNull($unserialized->prepareSqlExecutor($query));
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

    #[DataProvider('provideSerializedSingleSelectResults')]
    #[IgnoreDeprecations]
    public function testUnserializeSingleSelectResult(string $serialized): void
    {
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(ParserResult::class, $unserialized);
        $this->assertInstanceOf(ResultSetMapping::class, $unserialized->getResultSetMapping());
        $this->assertEquals(['name' => [0]], $unserialized->getParameterMappings());

        $this->expectDeprecationWithIdentifier('https://github.com/doctrine/orm/pull/11188');
        $this->assertInstanceOf(SingleSelectExecutor::class, $unserialized->getSqlExecutor());
        $this->assertIsString($unserialized->getSqlExecutor()->getSqlStatements());
    }

    /** @return Generator<string, array{string}> */
    public static function provideSerializedSingleSelectResults(): Generator
    {
        yield '2.17.0' => [rtrim(file_get_contents(__DIR__ . '/ParserResults/single_select_2_17_0.txt'), "\n")];
    }

    public function testUnserializeSingleSelectSqlFinalizerCachedBeforeSqlComments(): void
    {
        $class = SingleSelectSqlFinalizer::class;
        $sql   = 'SELECT 1';
        $name  = "\0" . $class . "\0sql";

        $finalizer = unserialize(sprintf(
            'O:%d:"%s":1:{s:%d:"%s";s:%d:"%s";}',
            strlen($class),
            $class,
            strlen($name),
            $name,
            strlen($sql),
            $sql,
        ));

        self::assertInstanceOf(SingleSelectSqlFinalizer::class, $finalizer);
        self::assertSame($sql, $finalizer->createExecutor($this->_em->createQuery())->getSqlStatements());
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
