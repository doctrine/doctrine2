<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadataFactory as PersistenceClassMetadataFactory;

/**
 * The ORM-specific contract for a class metadata factory.
 *
 * @extends PersistenceClassMetadataFactory<ClassMetadata<object>>
 */
interface ClassMetadataFactoryInterface extends PersistenceClassMetadataFactory
{
    /**
     * Injects the EntityManager using this CMF instance
     */
    public function setEntityManager(EntityManagerInterface $em): void;

    /**
     * Whether this class is mapped by the ORM.
     *
     * In addition to non-transient classes (see {@see isTransient()}), this also includes
     * embedded classes, which provide mapped fields as well.
     *
     * @param class-string $className
     */
    public function isMapped(string $className): bool;

    /**
     * @param A $maybeOwningSide
     *
     * @return (A is ManyToManyAssociationMapping ? ManyToManyOwningSideMapping : (
     *     A is OneToOneAssociationMapping ? OneToOneOwningSideMapping : (
     *     A is OneToManyAssociationMapping ? ManyToOneAssociationMapping : (
     *     A is ManyToOneAssociationMapping ? ManyToOneAssociationMapping :
     *     ManyToManyOwningSideMapping|OneToOneOwningSideMapping|ManyToOneAssociationMapping
     * ))))
     *
     * @template A of AssociationMapping
     */
    public function getOwningSide(AssociationMapping $maybeOwningSide): OwningSideMapping;
}
