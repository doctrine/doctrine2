<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;

use function assert;
use function implode;
use function is_object;

/**
 * The IdentifierFlattener utility now houses some of the identifier manipulation logic from unit of work, so that it
 * can be re-used elsewhere.
 */
final class IdentifierFlattener
{
    /**
     * The UnitOfWork used to coordinate object-level transactions.
     *
     * @var UnitOfWork
     */
    private $unitOfWork;

    /**
     * The metadata factory, used to retrieve the ORM metadata of entity classes.
     *
     * @var ClassMetadataFactory
     */
    private $metadataFactory;

    /**
     * Initializes a new IdentifierFlattener instance, bound to the given EntityManager.
     */
    public function __construct(UnitOfWork $unitOfWork, ClassMetadataFactory $metadataFactory)
    {
        $this->unitOfWork      = $unitOfWork;
        $this->metadataFactory = $metadataFactory;
    }

    /**
     * convert foreign identifiers into scalar foreign key values to avoid object to string conversion failures.
     *
     * @param mixed[] $id
     *
     * @return mixed[]
     * @psalm-return array<string, mixed>
     */
    public function flattenIdentifier(ClassMetadata $class, array $id): array
    {
        $flatId = [];

        foreach ($class->identifier as $field) {
            if (isset($class->associationMappings[$field]) && isset($id[$field]) && is_object($id[$field])) {
                $targetClassMetadata = $this->metadataFactory->getMetadataFor(
                    $class->associationMappings[$field]['targetEntity']
                );
                assert($targetClassMetadata instanceof ClassMetadata);

                if ($this->unitOfWork->isInIdentityMap($id[$field])) {
                    $associatedId = $this->flattenIdentifier($targetClassMetadata, $this->unitOfWork->getEntityIdentifier($id[$field]));
                } else {
                    $associatedId = $this->flattenIdentifier($targetClassMetadata, $targetClassMetadata->getIdentifierValues($id[$field]));
                }

                $flatId[$field] = implode(' ', $associatedId);
            } elseif (isset($class->associationMappings[$field])) {
                $associatedId = [];

                foreach ($class->associationMappings[$field]['joinColumns'] as $joinColumn) {
                    $associatedId[] = $id[$joinColumn['name']];
                }

                $flatId[$field] = implode(' ', $associatedId);
            } else {
                $flatId[$field] = $id[$field];
            }
        }

        return $flatId;
    }
}
