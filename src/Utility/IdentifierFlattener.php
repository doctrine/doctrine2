<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use BackedEnum;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Mapping\ClassMetadataFactory;

use function assert;
use function implode;
use function is_a;
use function is_object;

/**
 * The IdentifierFlattener utility now houses some of the identifier manipulation logic from unit of work, so that it
 * can be re-used elsewhere.
 */
final class IdentifierFlattener
{
    /**
     * Initializes a new IdentifierFlattener instance, bound to the given EntityManager.
     */
    public function __construct(
        /**
         * The UnitOfWork used to coordinate object-level transactions.
         */
        private readonly UnitOfWork $unitOfWork,
        /**
         * The metadata factory, used to retrieve the ORM metadata of entity classes.
         */
        private readonly ClassMetadataFactory $metadataFactory,
    ) {
    }

    /**
     * convert foreign identifiers into scalar foreign key values to avoid object to string conversion failures.
     *
     * @param mixed[] $id
     *
     * @return mixed[]
     * @phpstan-return array<string, mixed>
     */
    public function flattenIdentifier(ClassMetadata $class, array $id): array
    {
        $flatId = [];

        foreach ($class->identifier as $field) {
            if (isset($class->associationMappings[$field]) && isset($id[$field]) && is_object($id[$field]) && is_a($id[$field], $class->associationMappings[$field]->targetEntity)) {
                $targetClassMetadata = $this->metadataFactory->getMetadataFor(
                    $class->associationMappings[$field]->targetEntity,
                );
                assert($targetClassMetadata instanceof ClassMetadata);

                if ($this->unitOfWork->isInIdentityMap($id[$field])) {
                    $associatedId = $this->flattenIdentifier($targetClassMetadata, $this->unitOfWork->getEntityIdentifier($id[$field]));
                } else {
                    $associatedId = $this->flattenIdentifier($targetClassMetadata, $targetClassMetadata->getIdentifierValues($id[$field]));
                }

                $flatId[$field] = implode(' ', $associatedId);
            } elseif (isset($class->associationMappings[$field])) {
                assert($class->associationMappings[$field]->isToOneOwningSide());
                $associatedId = [];

                foreach ($class->associationMappings[$field]->joinColumns as $joinColumn) {
                    $associatedId[] = $id[$joinColumn->name];
                }

                $flatId[$field] = implode(' ', $associatedId);
            } else {
                if ($id[$field] instanceof BackedEnum) {
                    $flatId[$field] = $id[$field]->value;
                } else {
                    $flatId[$field] = $id[$field];
                }
            }
        }

        return $flatId;
    }
}
