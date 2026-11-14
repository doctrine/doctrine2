<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Internal\Query;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Internal\Query\TemporaryIdTable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\OrmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('GH-12634')]
class TemporaryIdTableTest extends OrmTestCase
{
    private const MYSQL   = ['driver' => 'pdo_mysql', 'serverVersion' => '8.0.36'];
    private const MARIADB = ['driver' => 'pdo_mysql', 'serverVersion' => '10.11.6-MariaDB'];
    private const PGSQL   = ['driver' => 'pdo_pgsql', 'serverVersion' => '14.9'];
    private const SQLSRV  = ['driver' => 'pdo_sqlsrv', 'serverVersion' => '16.0.1000'];
    private const SQLITE  = ['driver' => 'pdo_sqlite', 'memory' => true];

    private const UNICODE_TABLE_OPTIONS = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'];
    private const UNICODE_SQL           = ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`';

    private const MYSQL_UNICODE   = self::MYSQL + ['defaultTableOptions' => self::UNICODE_TABLE_OPTIONS];
    private const MARIADB_UNICODE = self::MARIADB + ['defaultTableOptions' => self::UNICODE_TABLE_OPTIONS];
    private const PGSQL_UNICODE   = self::PGSQL + ['defaultTableOptions' => self::UNICODE_TABLE_OPTIONS];
    private const SQLSRV_UNICODE  = self::SQLSRV + ['defaultTableOptions' => self::UNICODE_TABLE_OPTIONS];
    private const SQLITE_UNICODE  = self::SQLITE + ['defaultTableOptions' => self::UNICODE_TABLE_OPTIONS];

    private const MYSQL_COLLATION_ONLY     = self::MYSQL + ['defaultTableOptions' => ['collation' => 'utf8mb4_bin']];
    private const MYSQL_COLLATE_OPTION     = self::MYSQL + ['defaultTableOptions' => ['charset' => 'utf8mb4', 'collate' => 'utf8mb4_bin']];
    private const MYSQL_CONNECTION_CHARSET = self::MYSQL + ['charset' => 'utf8mb4'];
    private const MYSQL_LATIN1_CONNECTION  = self::MYSQL_UNICODE + ['charset' => 'latin1'];

    private const INTEGER_SQL      = 'CREATE TEMPORARY TABLE id_tmp (id INT NOT NULL, PRIMARY KEY(id))';
    private const FIXED_STRING_SQL = 'CREATE TEMPORARY TABLE id_tmp (id CHAR(36) NOT NULL, PRIMARY KEY(id))';
    private const STRING_SQL       = 'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(255) NOT NULL, PRIMARY KEY(id))';

    /**
     * @param class-string         $entity
     * @param array<string, mixed> $connectionParams
     */
    #[DataProvider('provideColumnDeclarations')]
    public function testColumnsAreDeclaredLikeTheIdentifierColumnsOfTheRootTable(
        string $entity,
        array $connectionParams,
        string $expectedSql,
    ): void {
        self::assertSame($expectedSql, $this->getCreateSql($entity, $connectionParams));
    }

    /** @return iterable<string, array{class-string, array<string, mixed>, string}> */
    public static function provideColumnDeclarations(): iterable
    {
        yield 'MySQL, integer' => [TemporaryIdTableIntegerId::class, self::MYSQL, self::INTEGER_SQL];

        yield 'MySQL, unsigned integer' => [
            TemporaryIdTableUnsignedIntegerId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (id INT UNSIGNED NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'MySQL, fixed length string' => [TemporaryIdTableFixedStringId::class, self::MYSQL, self::FIXED_STRING_SQL];
        yield 'MySQL, string without length' => [TemporaryIdTableStringId::class, self::MYSQL, self::STRING_SQL];

        yield 'MySQL, decimal' => [
            TemporaryIdTableDecimalId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (id NUMERIC(12, 4) NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'MySQL, fixed length binary' => [
            TemporaryIdTableBinaryId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (id BINARY(16) NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'MySQL, guid' => [TemporaryIdTableGuidId::class, self::MYSQL, self::FIXED_STRING_SQL];

        yield 'MySQL, composite identifier' => [
            TemporaryIdTableCompositeId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (code VARCHAR(10) NOT NULL, number INT NOT NULL, PRIMARY KEY(code,number))',
        ];

        yield 'MySQL, identifier derived from an association' => [
            TemporaryIdTableAssociationId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (owner_id CHAR(36) NOT NULL, PRIMARY KEY(owner_id))',
        ];

        yield 'MySQL, collation of the column' => [
            TemporaryIdTableCollatedId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(20) NOT NULL COLLATE `utf8mb4_bin`, PRIMARY KEY(id))',
        ];

        yield 'MySQL, charset and collation of the column' => [
            TemporaryIdTableCharsetId::class,
            self::MYSQL,
            'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(20) CHARACTER SET latin1 NOT NULL COLLATE `latin1_bin`, PRIMARY KEY(id))',
        ];

        yield 'MariaDB, fixed length string' => [TemporaryIdTableFixedStringId::class, self::MARIADB, self::FIXED_STRING_SQL];

        yield 'PostgreSQL, fixed length string' => [TemporaryIdTableFixedStringId::class, self::PGSQL, self::FIXED_STRING_SQL];
        yield 'PostgreSQL, string without length' => [TemporaryIdTableStringId::class, self::PGSQL, self::STRING_SQL];

        yield 'PostgreSQL, decimal' => [
            TemporaryIdTableDecimalId::class,
            self::PGSQL,
            'CREATE TEMPORARY TABLE id_tmp (id NUMERIC(12, 4) NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'PostgreSQL, collation of the column' => [
            TemporaryIdTableCollatedId::class,
            self::PGSQL,
            'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(20) NOT NULL COLLATE "utf8mb4_bin", PRIMARY KEY(id))',
        ];

        yield 'SQLite, fixed length string' => [TemporaryIdTableFixedStringId::class, self::SQLITE, self::FIXED_STRING_SQL];
        yield 'SQLite, string without length' => [TemporaryIdTableStringId::class, self::SQLITE, self::STRING_SQL];

        yield 'SQL Server, fixed length string' => [
            TemporaryIdTableFixedStringId::class,
            self::SQLSRV,
            'CREATE TABLE id_tmp (id NCHAR(36) NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'SQL Server, string without length' => [
            TemporaryIdTableStringId::class,
            self::SQLSRV,
            'CREATE TABLE id_tmp (id NVARCHAR(255) NOT NULL, PRIMARY KEY(id))',
        ];
    }

    public function testStringWithoutLengthGetsTheDefaultStringLengthOfTheConfiguration(): void
    {
        $em = $this->createEntityManager(self::MYSQL);
        $em->getConfiguration()->setDefaultStringTypeSchemaLength(64);

        self::assertSame(
            'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(64) NOT NULL, PRIMARY KEY(id))',
            TemporaryIdTable::getCreateSQL('id_tmp', $em->getClassMetadata(TemporaryIdTableStringId::class), $em),
        );
    }

    /**
     * @param class-string         $entity
     * @param array<string, mixed> $connectionParams
     */
    #[DataProvider('provideTableOptions')]
    public function testCharsetAndCollationOfTheTableAreDeclaredForStringIdentifiers(
        string $entity,
        array $connectionParams,
        string $expectedSql,
    ): void {
        self::assertSame($expectedSql, $this->getCreateSql($entity, $connectionParams));
    }

    /** @return iterable<string, array{class-string, array<string, mixed>, string}> */
    public static function provideTableOptions(): iterable
    {
        yield 'default table options' => [
            TemporaryIdTableFixedStringId::class,
            self::MYSQL_UNICODE,
            self::FIXED_STRING_SQL . self::UNICODE_SQL,
        ];

        yield 'default table options, MariaDB' => [
            TemporaryIdTableFixedStringId::class,
            self::MARIADB_UNICODE,
            self::FIXED_STRING_SQL . self::UNICODE_SQL,
        ];

        yield 'default table options, string without length' => [
            TemporaryIdTableStringId::class,
            self::MYSQL_UNICODE,
            self::STRING_SQL . self::UNICODE_SQL,
        ];

        yield 'default table options, guid' => [
            TemporaryIdTableGuidId::class,
            self::MYSQL_UNICODE,
            self::FIXED_STRING_SQL . self::UNICODE_SQL,
        ];

        yield 'default table options, identifier derived from an association' => [
            TemporaryIdTableAssociationId::class,
            self::MYSQL_UNICODE,
            'CREATE TEMPORARY TABLE id_tmp (owner_id CHAR(36) NOT NULL, PRIMARY KEY(owner_id))' . self::UNICODE_SQL,
        ];

        yield 'default table options, ascii string' => [
            TemporaryIdTableAsciiStringId::class,
            self::MYSQL_UNICODE,
            'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(26) NOT NULL, PRIMARY KEY(id))' . self::UNICODE_SQL,
        ];

        yield 'default table options, collation of the column' => [
            TemporaryIdTableCollatedId::class,
            self::MYSQL_UNICODE,
            'CREATE TEMPORARY TABLE id_tmp (id VARCHAR(20) NOT NULL COLLATE `utf8mb4_bin`, PRIMARY KEY(id))' . self::UNICODE_SQL,
        ];

        yield 'collation only' => [
            TemporaryIdTableFixedStringId::class,
            self::MYSQL_COLLATION_ONLY,
            self::FIXED_STRING_SQL . ' COLLATE `utf8mb4_bin`',
        ];

        yield 'deprecated collate option' => [
            TemporaryIdTableFixedStringId::class,
            self::MYSQL_COLLATE_OPTION,
            self::FIXED_STRING_SQL . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin`',
        ];

        yield 'charset of the connection' => [
            TemporaryIdTableFixedStringId::class,
            self::MYSQL_CONNECTION_CHARSET,
            self::FIXED_STRING_SQL . ' DEFAULT CHARACTER SET utf8mb4',
        ];

        yield 'charset of the default table options wins over the one of the connection' => [
            TemporaryIdTableFixedStringId::class,
            self::MYSQL_LATIN1_CONNECTION,
            self::FIXED_STRING_SQL . self::UNICODE_SQL,
        ];

        yield 'options of the table win over the default table options' => [
            TemporaryIdTableWithTableOptions::class,
            self::MYSQL_UNICODE,
            self::FIXED_STRING_SQL . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin`',
        ];

        yield 'options of the table without default table options' => [
            TemporaryIdTableWithTableOptions::class,
            self::MYSQL,
            self::FIXED_STRING_SQL . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin`',
        ];

        yield 'options of the table are completed by the default table options' => [
            TemporaryIdTableWithTableCollation::class,
            self::MYSQL_UNICODE,
            self::FIXED_STRING_SQL . ' DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin`',
        ];

        yield 'no table options configured' => [TemporaryIdTableFixedStringId::class, self::MYSQL, self::FIXED_STRING_SQL];

        yield 'integer identifier' => [TemporaryIdTableIntegerId::class, self::MYSQL_UNICODE, self::INTEGER_SQL];

        yield 'binary identifier' => [
            TemporaryIdTableBinaryId::class,
            self::MYSQL_UNICODE,
            'CREATE TEMPORARY TABLE id_tmp (id BINARY(16) NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'PostgreSQL' => [TemporaryIdTableFixedStringId::class, self::PGSQL_UNICODE, self::FIXED_STRING_SQL];

        yield 'SQL Server' => [
            TemporaryIdTableFixedStringId::class,
            self::SQLSRV_UNICODE,
            'CREATE TABLE id_tmp (id NCHAR(36) NOT NULL, PRIMARY KEY(id))',
        ];

        yield 'SQLite' => [TemporaryIdTableFixedStringId::class, self::SQLITE_UNICODE, self::FIXED_STRING_SQL];
    }

    /** @param array<string, mixed> $connectionParams */
    private function getCreateSql(string $entity, array $connectionParams): string
    {
        $em = $this->createEntityManager($connectionParams);

        return TemporaryIdTable::getCreateSQL('id_tmp', $em->getClassMetadata($entity), $em);
    }

    /** @param array<string, mixed> $connectionParams */
    private function createEntityManager(array $connectionParams): EntityManagerMock
    {
        return $this->createTestEntityManagerWithConnection(DriverManager::getConnection($connectionParams));
    }
}

#[Entity]
class TemporaryIdTableIntegerId
{
    #[Id]
    #[Column(type: 'integer')]
    public int $id;
}

#[Entity]
class TemporaryIdTableUnsignedIntegerId
{
    #[Id]
    #[Column(type: 'integer', options: ['unsigned' => true])]
    public int $id;
}

#[Entity]
class TemporaryIdTableFixedStringId
{
    #[Id]
    #[Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;
}

#[Entity]
class TemporaryIdTableStringId
{
    #[Id]
    #[Column(type: 'string')]
    public string $id;
}

#[Entity]
class TemporaryIdTableDecimalId
{
    #[Id]
    #[Column(type: 'decimal', precision: 12, scale: 4)]
    public string $id;
}

#[Entity]
class TemporaryIdTableBinaryId
{
    #[Id]
    #[Column(type: 'binary', length: 16, options: ['fixed' => true])]
    public string $id;
}

#[Entity]
class TemporaryIdTableGuidId
{
    #[Id]
    #[Column(type: 'guid')]
    public string $id;
}

#[Entity]
class TemporaryIdTableAsciiStringId
{
    #[Id]
    #[Column(type: 'ascii_string', length: 26)]
    public string $id;
}

#[Entity]
class TemporaryIdTableCompositeId
{
    #[Id]
    #[Column(type: 'string', length: 10)]
    public string $code;

    #[Id]
    #[Column(type: 'integer')]
    public int $number;
}

#[Entity]
class TemporaryIdTableAssociationId
{
    #[Id]
    #[OneToOne(targetEntity: TemporaryIdTableFixedStringId::class)]
    #[JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
    public TemporaryIdTableFixedStringId $owner;
}

#[Entity]
class TemporaryIdTableCollatedId
{
    #[Id]
    #[Column(type: 'string', length: 20, options: ['collation' => 'utf8mb4_bin'])]
    public string $id;
}

#[Entity]
class TemporaryIdTableCharsetId
{
    #[Id]
    #[Column(type: 'string', length: 20, options: ['charset' => 'latin1', 'collation' => 'latin1_bin'])]
    public string $id;
}

#[Entity]
#[Table(options: ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'])]
class TemporaryIdTableWithTableOptions
{
    #[Id]
    #[Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;
}

#[Entity]
#[Table(options: ['collation' => 'utf8mb4_bin'])]
class TemporaryIdTableWithTableCollation
{
    #[Id]
    #[Column(type: 'string', length: 36, options: ['fixed' => true])]
    public string $id;
}
