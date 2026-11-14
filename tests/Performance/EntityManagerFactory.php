<?php

declare(strict_types=1);

namespace Doctrine\Performance;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Result;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Tests\Mocks\ArrayResultFactory;
use Doctrine\Tests\Mocks\AttributeDriverFactory;

use function array_map;

final class EntityManagerFactory
{
    public static function getEntityManager(array $schemaClassNames): EntityManagerInterface
    {
        $config = new Configuration();

        $config->setMetadataDriverImpl(AttributeDriverFactory::createAttributeDriver([
            __DIR__ . '/../Tests/Models/Cache',
            __DIR__ . '/../Tests/Models/GeoNames',
        ]));

        $entityManager = new EntityManager(
            DriverManager::getConnection([
                'driverClass' => Driver::class,
                'memory'      => true,
            ], $config),
            $config,
        );

        (new SchemaTool($entityManager))
            ->createSchema(array_map([$entityManager, 'getClassMetadata'], $schemaClassNames));

        return $entityManager;
    }

    public static function makeEntityManagerWithNoResultsConnection(): EntityManagerInterface
    {
        $config = new Configuration();

        $config->setMetadataDriverImpl(AttributeDriverFactory::createAttributeDriver([
            __DIR__ . '/../Tests/Models/Cache',
            __DIR__ . '/../Tests/Models/Generic',
            __DIR__ . '/../Tests/Models/GeoNames',
        ]));

        // A connection that doesn't really do anything
        $connection = new class ([], new Driver(), null, new EventManager()) extends Connection
        {
            /** {@inheritDoc} */
            public function executeQuery(string $sql, array $params = [], $types = [], QueryCacheProfile|null $qcp = null): Result
            {
                return ArrayResultFactory::createWrapperResultFromArray([], $this);
            }
        };

        return new EntityManager($connection, $config);
    }
}
