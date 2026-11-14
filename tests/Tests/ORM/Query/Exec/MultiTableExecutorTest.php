<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Query\Exec;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Query\Parser;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function end;

#[Group('GH-12634')]
class MultiTableExecutorTest extends OrmTestCase
{
    private const MYSQL = [
        'driver' => 'pdo_mysql',
        'serverVersion' => '8.0.36',
        'defaultTableOptions' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
    ];

    private const SQLSRV = ['driver' => 'pdo_sqlsrv', 'serverVersion' => '16.0.1000'];

    /** @param array<string, mixed> $connectionParams */
    #[DataProvider('provideStatements')]
    public function testTemporaryTableIsDeclaredLikeTheIdentifierColumnOfTheRootTable(
        string $dql,
        array $connectionParams,
        string $expectedCreateSql,
        string $expectedDropSql,
    ): void {
        $em = $this->createTestEntityManagerWithConnection(DriverManager::getConnection($connectionParams));

        $statements = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            },
        );

        $query = $em->createQuery($dql);

        (new Parser($query))->parse()->prepareSqlExecutor($query)->execute($connection, [], []);

        self::assertSame($expectedCreateSql, $statements[0]);
        self::assertSame($expectedDropSql, end($statements));
    }

    /** @return iterable<string, array{string, array<string, mixed>, string, string}> */
    public static function provideStatements(): iterable
    {
        $update = 'UPDATE ' . MultiTableExecutorChild::class . ' c SET c.status = 3';
        $delete = 'DELETE ' . MultiTableExecutorChild::class . ' c';

        $mysqlCreate = 'CREATE TEMPORARY TABLE multi_table_executor_root_id_tmp (id CHAR(36) NOT NULL, PRIMARY KEY(id))'
            . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`';
        $mysqlDrop   = 'DROP TEMPORARY TABLE multi_table_executor_root_id_tmp';

        $sqlsrvCreate = 'CREATE TABLE #multi_table_executor_root_id_tmp (id NCHAR(36) NOT NULL, PRIMARY KEY(id))';
        $sqlsrvDrop   = 'DROP TABLE #multi_table_executor_root_id_tmp';

        yield 'MySQL, UPDATE' => [$update, self::MYSQL, $mysqlCreate, $mysqlDrop];
        yield 'MySQL, DELETE' => [$delete, self::MYSQL, $mysqlCreate, $mysqlDrop];
        yield 'SQL Server, UPDATE' => [$update, self::SQLSRV, $sqlsrvCreate, $sqlsrvDrop];
        yield 'SQL Server, DELETE' => [$delete, self::SQLSRV, $sqlsrvCreate, $sqlsrvDrop];
    }
}

#[Entity]
#[Table(name: 'multi_table_executor_root')]
#[InheritanceType('JOINED')]
#[DiscriminatorColumn(name: 'discr', type: 'string')]
#[DiscriminatorMap(['root' => MultiTableExecutorRoot::class, 'child' => MultiTableExecutorChild::class])]
class MultiTableExecutorRoot
{
    #[Id]
    #[Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;

    #[Column(type: 'integer')]
    public int $status = 1;
}

#[Entity]
#[Table(name: 'multi_table_executor_child')]
class MultiTableExecutorChild extends MultiTableExecutorRoot
{
    #[Column(type: 'string', length: 20)]
    public string $label;
}
