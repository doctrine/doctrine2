<?php

declare(strict_types=1);

namespace Doctrine\ORM\Proxy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use ReflectionClass;

/**
 * This factory is used to create proxy objects for entities at runtime.
 */
class ProxyFactory
{
    /** The UnitOfWork this factory uses to retrieve persisters */
    private readonly UnitOfWork $uow;

    /** The IdentifierFlattener used for manipulating identifiers */
    private readonly IdentifierFlattener $identifierFlattener;

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>EntityManager</tt>.
     *
     * @param EntityManagerInterface $em The EntityManager the new factory works for.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        $this->uow                 = $em->getUnitOfWork();
        $this->identifierFlattener = new IdentifierFlattener($this->uow, $em->getMetadataFactory());
    }

    /** @param array<string, mixed> $entityIdentifier */
    public function getEmbeddableProxy(string $className, object $parentEntity, array $entityIdentifier): object
    {
        $classMetadata       = $this->em->getClassMetadata($className);
        $entityPersister     = $this->uow->getEntityPersister($parentEntity::class);
        $identifierFlattener = $this->identifierFlattener;
        $uow                 = $this->uow;

        $cb = static function (object $object) use (
            $entityIdentifier,
            $entityPersister,
            $identifierFlattener,
            $classMetadata,
            $parentEntity,
            $uow,
        ): void {
            // If the parent entity was already fully initialized (e.g. because
            // another of its properties was accessed first), all embedded fields
            // were already written as raw values onto this ghost during that
            // hydration pass. PHP will materialize those raw values automatically
            // once the initializer returns, so no SELECT is needed.
            if (! $uow->isUninitializedObject($parentEntity)) {
                return;
            }

            $original = $entityPersister->loadById($entityIdentifier, $parentEntity);
            if ($original === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $identifierFlattener->flattenIdentifier($classMetadata, $entityIdentifier),
                );
            }
        };

        return $classMetadata->reflClass->newLazyGhost($cb, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
    }

    /**
     * @param class-string $className
     * @param array<mixed> $identifier
     */
    public function getProxy(string $className, array $identifier, bool $assignIdentifiers = true): object
    {
        $classMetadata       = $this->em->getClassMetadata($className);
        $entityPersister     = $this->uow->getEntityPersister($className);
        $identifierFlattener = $this->identifierFlattener;

        $proxy = $classMetadata->reflClass->newLazyGhost(static function (object $object) use (
            $identifier,
            $entityPersister,
            $identifierFlattener,
            $classMetadata,
        ): void {
            $original = $entityPersister->loadById($identifier, $object);
            if ($original === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $identifierFlattener->flattenIdentifier($classMetadata, $identifier),
                );
            }
        }, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);

        if ($assignIdentifiers) {
            foreach ($identifier as $idField => $value) {
                $classMetadata->propertyAccessors[$idField]->setValue($proxy, $value);
            }
        }

        return $proxy;
    }
}
