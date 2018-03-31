<?php

declare(strict_types=1);

namespace Doctrine\Performance;

use Doctrine\DBAL\Driver\PDOSqlite\Driver;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Proxy\Factory\StaticProxyFactory;
use Doctrine\ORM\Tools\SchemaTool;
use function array_map;
use function realpath;

final class EntityManagerFactory
{
    public static function getEntityManager(array $schemaClassNames) : EntityManagerInterface
    {
        $config = new Configuration();

        $config->setProxyDir(__DIR__ . '/../Tests/Proxies');
        $config->setProxyNamespace('Doctrine\Tests\Proxies');
        $config->setAutoGenerateProxyClasses(StaticProxyFactory::AUTOGENERATE_EVAL);
        $config->setMetadataDriverImpl(
            $config->newDefaultAnnotationDriver([
                realpath(__DIR__ . '/Models/Cache'),
                realpath(__DIR__ . '/Models/GeoNames'),
            ])
        );

        $connection    = DriverManager::getConnection(
            [
                'driverClass' => Driver::class,
                'memory'      => true,
            ]
        );
        $entityManager = EntityManager::create($connection, $config);

        (new SchemaTool($entityManager))
            ->createSchema(array_map([$entityManager, 'getClassMetadata'], $schemaClassNames));

        return $entityManager;
    }
}
