<?php

declare(strict_types=1);

namespace Doctrine\ORM\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ObjectRepository;
use Override;

use function spl_object_id;

/**
 * This factory is used to create default repository objects for entities at runtime.
 */
final class DefaultRepositoryFactory implements RepositoryFactory
{
    /**
     * The list of EntityRepository instances.
     *
     * @var ObjectRepository[]
     * @phpstan-var array<string, EntityRepository>
     */
    private array $repositoryList = [];

    #[Override]
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $repositoryHash = $entityManager->getClassMetadata($entityName)->getName() . spl_object_id($entityManager);

        return $this->repositoryList[$repositoryHash] ??= $this->createRepository($entityManager, $entityName);
    }

    /**
     * Create a new repository instance for an entity class.
     *
     * @param EntityManagerInterface $entityManager The EntityManager instance.
     * @param string                 $entityName    The name of the entity.
     */
    private function createRepository(
        EntityManagerInterface $entityManager,
        string $entityName,
    ): EntityRepository {
        $metadata            = $entityManager->getClassMetadata($entityName);
        $repositoryClassName = $metadata->customRepositoryClassName
            ?: $entityManager->getConfiguration()->getDefaultRepositoryClassName();

        return new $repositoryClassName($entityManager, $metadata);
    }
}
