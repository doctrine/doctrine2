<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Persisters;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Doctrine\Persistence\Mapping\Driver\ClassLocator;
use Doctrine\Persistence\Mapping\Driver\FileClassLocator;
use Doctrine\Tests\Mocks\AttributeDriverFactory;
use Doctrine\Tests\Mocks\EntityManagerMock;
use Doctrine\Tests\Models\BinaryPrimaryKey\BinaryIdType;
use Doctrine\Tests\Models\BinaryPrimaryKey\Category;
use Doctrine\Tests\OrmTestCase;

use function method_exists;

use const PHP_VERSION_ID;

final class BinaryIdPersisterTest extends OrmTestCase
{
    private EntityManager|null $entityManager = null;

    public function testOneToManyWithEagerFetchMode(): void
    {
        $entityManager = $this->createEntityManager();

        $this->createDummyBlogData($entityManager, 3);

        $categories = $entityManager->createQueryBuilder()
            ->select('category')
            ->from(Category::class, 'category')
            ->getQuery()
            ->setFetchMode(Category::class, 'children', ClassMetadata::FETCH_EAGER)
            ->getResult();

        self::assertCount(3, $categories);
    }

    private function createDummyBlogData(
        EntityManager $entityManager,
        int $categoryCount = 1,
        int $categoryParentsCount = 0,
    ): void {
        for ($h = 0; $h < $categoryCount; $h++) {
            $categoryParent = null;

            for ($i = 0; $i < $categoryParentsCount; $i++) {
                $categoryParent = new Category('CategoryParent#' . $i, $categoryParent);
                $entityManager->persist($categoryParent);
            }

            $category = new Category('Category#' . $h, $categoryParent);
            $entityManager->persist($category);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function createEntityManager(): EntityManager
    {
        if ($this->entityManager !== null) {
            return $this->entityManager;
        }

        $method = PHP_VERSION_ID >= 80400 ? 'createAttributeMetadataConfig' : 'createAttributeMetadataConfiguration';
        $config = ORMSetup::$method(
            $this->getClassLocator(),
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(PHP_VERSION_ID >= 80400);

        if (method_exists(Schema::class, 'edit')) {
            $config->setUseSchemaEditorApi(true);
        }

        if (method_exists($config, 'setTypeProvider')) {
            $config->setTypeProvider(self::getTypeRegistry());
        }

        self::registerDbalType(BinaryIdType::NAME, BinaryIdType::class);

        $connection    = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManagerMock($connection, $config);

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $schemaValidator = new SchemaValidator($entityManager);
        $schemaValidator->validateMapping();

        $this->entityManager = $entityManager;

        return $entityManager;
    }

    /** @return list<string>|ClassLocator */
    private function getClassLocator(): array|ClassLocator
    {
        $paths = [__DIR__ . '/../../Models/BinaryPrimaryKey'];

        if (! AttributeDriverFactory::isClassLocatorSupported()) {
            return $paths;
        }

        return FileClassLocator::createFromDirectories($paths);
    }
}
