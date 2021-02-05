<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Closure;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\ToolsException;
use Doctrine\Tests\OrmFunctionalTestCase;

use function call_user_func_array;
use function debug_backtrace;

use const PHP_INT_MAX;

/**
 * @group DDC-3634
 */
class DDC3634Test extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = $this->_em->getClassMetadata(DDC3634Entity::class);

        if (! $metadata->idGenerator->isPostInsertGenerator()) {
            $this->markTestSkipped('Need a post-insert ID generator in order to make this test work correctly');
        }

        try {
            $this->_schemaTool->createSchema([
                $metadata,
                $this->_em->getClassMetadata(DDC3634JTIBaseEntity::class),
                $this->_em->getClassMetadata(DDC3634JTIChildEntity::class),
            ]);
        } catch (ToolsException $e) {
            // schema already in place
        }
    }

    public function testSavesVeryLargeIntegerAutoGeneratedValue(): void
    {
        $veryLargeId = PHP_INT_MAX . PHP_INT_MAX;

        $entityManager = EntityManager::create(
            new DDC3634LastInsertIdMockingConnection($veryLargeId, $this->_em->getConnection()),
            $this->_em->getConfiguration()
        );

        $entity = new DDC3634Entity();

        $entityManager->persist($entity);
        $entityManager->flush();

        $this->assertSame($veryLargeId, $entity->id);
    }

    public function testSavesIntegerAutoGeneratedValueAsString(): void
    {
        $entity = new DDC3634Entity();

        $this->_em->persist($entity);
        $this->_em->flush();

        $this->assertIsString($entity->id);
    }

    public function testSavesIntegerAutoGeneratedValueAsStringWithJoinedInheritance(): void
    {
        $entity = new DDC3634JTIChildEntity();

        $this->_em->persist($entity);
        $this->_em->flush();

        $this->assertIsString($entity->id);
    }
}

/** @Entity */
class DDC3634Entity
{
    /** @Id @Column(type="bigint") @GeneratedValue(strategy="AUTO") */
    public $id;
}

/**
 * @Entity
 * @InheritanceType("JOINED")
 * @DiscriminatorMap({
 *  DDC3634JTIBaseEntity::class  = DDC3634JTIBaseEntity::class,
 *  DDC3634JTIChildEntity::class = DDC3634JTIChildEntity::class,
 * })
 */
class DDC3634JTIBaseEntity
{
    /** @Id @Column(type="bigint") @GeneratedValue(strategy="AUTO") */
    public $id;
}

/** @Entity */
class DDC3634JTIChildEntity extends DDC3634JTIBaseEntity
{
}

class DDC3634LastInsertIdMockingConnection extends Connection
{
    /** @var Connection */
    private $realConnection;

    /** @var int|string */
    private $identifier;

    /**
     * @param int|string $identifier
     */
    public function __construct($identifier, Connection $realConnection)
    {
        $this->realConnection = $realConnection;
        $this->identifier     = $identifier;
    }

    private function forwardCall()
    {
        $trace = debug_backtrace(0, 2)[1];

        return call_user_func_array([$this->realConnection, $trace['function']], $trace['args']);
    }

    public function getParams()
    {
        return $this->forwardCall();
    }

    public function getDatabase()
    {
        return $this->forwardCall();
    }

    public function getHost()
    {
        return $this->forwardCall();
    }

    public function getPort()
    {
        return $this->forwardCall();
    }

    public function getUsername()
    {
        return $this->forwardCall();
    }

    public function getPassword()
    {
        return $this->forwardCall();
    }

    public function getDriver()
    {
        return $this->forwardCall();
    }

    public function getConfiguration()
    {
        return $this->forwardCall();
    }

    public function getEventManager()
    {
        return $this->forwardCall();
    }

    public function getDatabasePlatform()
    {
        return $this->forwardCall();
    }

    public function getExpressionBuilder()
    {
        return $this->forwardCall();
    }

    public function connect()
    {
        return $this->forwardCall();
    }

    public function isAutoCommit()
    {
        return $this->forwardCall();
    }

    public function setAutoCommit($autoCommit)
    {
        return $this->forwardCall();
    }

    public function setFetchMode($fetchMode)
    {
        return $this->forwardCall();
    }

    public function fetchAssoc($statement, array $params = [], array $types = [])
    {
        return $this->forwardCall();
    }

    public function fetchArray($statement, array $params = [], array $types = [])
    {
        return $this->forwardCall();
    }

    public function fetchColumn($statement, array $params = [], $column = 0, array $types = [])
    {
        return $this->forwardCall();
    }

    public function isConnected()
    {
        return $this->forwardCall();
    }

    public function isTransactionActive()
    {
        return $this->forwardCall();
    }

    public function delete($tableExpression, array $identifier, array $types = [])
    {
        return $this->forwardCall();
    }

    public function close()
    {
        return $this->forwardCall();
    }

    public function setTransactionIsolation($level)
    {
        return $this->forwardCall();
    }

    public function getTransactionIsolation()
    {
        return $this->forwardCall();
    }

    public function update($tableExpression, array $data, array $identifier, array $types = [])
    {
        return $this->forwardCall();
    }

    public function insert($tableExpression, array $data, array $types = [])
    {
        return $this->forwardCall();
    }

    public function quoteIdentifier($str)
    {
        return $this->forwardCall();
    }

    public function quote($input, $type = null)
    {
        return $this->forwardCall();
    }

    public function fetchAll($sql, array $params = [], $types = [])
    {
        return $this->forwardCall();
    }

    public function prepare($statement)
    {
        return $this->forwardCall();
    }

    public function executeQuery($query, array $params = [], $types = [], ?QueryCacheProfile $qcp = null)
    {
        return $this->forwardCall();
    }

    public function executeCacheQuery($query, $params, $types, QueryCacheProfile $qcp)
    {
        return $this->forwardCall();
    }

    public function project($query, array $params, Closure $function)
    {
        return $this->forwardCall();
    }

    public function query()
    {
        return $this->forwardCall();
    }

    public function executeUpdate($query, array $params = [], array $types = [])
    {
        return $this->forwardCall();
    }

    public function exec($statement)
    {
        return $this->forwardCall();
    }

    public function getTransactionNestingLevel()
    {
        return $this->forwardCall();
    }

    public function errorCode()
    {
        return $this->forwardCall();
    }

    public function errorInfo()
    {
        return $this->forwardCall();
    }

    public function lastInsertId($seqName = null)
    {
        return $this->identifier;
    }

    public function transactional(Closure $func)
    {
        return $this->forwardCall();
    }

    public function setNestTransactionsWithSavepoints($nestTransactionsWithSavepoints)
    {
        return $this->forwardCall();
    }

    public function getNestTransactionsWithSavepoints()
    {
        return $this->forwardCall();
    }

    protected function _getNestedTransactionSavePointName()
    {
        return $this->forwardCall();
    }

    public function beginTransaction()
    {
        return $this->forwardCall();
    }

    public function commit()
    {
        return $this->forwardCall();
    }

    public function rollBack()
    {
        return $this->forwardCall();
    }

    public function createSavepoint($savepoint)
    {
        return $this->forwardCall();
    }

    public function releaseSavepoint($savepoint)
    {
        return $this->forwardCall();
    }

    public function rollbackSavepoint($savepoint)
    {
        return $this->forwardCall();
    }

    public function getWrappedConnection()
    {
        return $this->forwardCall();
    }

    public function getSchemaManager()
    {
        return $this->forwardCall();
    }

    public function setRollbackOnly()
    {
        return $this->forwardCall();
    }

    public function isRollbackOnly()
    {
        return $this->forwardCall();
    }

    public function convertToDatabaseValue($value, $type)
    {
        return $this->forwardCall();
    }

    public function convertToPHPValue($value, $type)
    {
        return $this->forwardCall();
    }

    public function resolveParams(array $params, array $types)
    {
        return $this->forwardCall();
    }

    public function createQueryBuilder()
    {
        return $this->forwardCall();
    }

    public function ping()
    {
        return $this->forwardCall();
    }
}
