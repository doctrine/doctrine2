<?php

declare(strict_types=1);

namespace Doctrine\ORM\Id;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityMissingAssignedId;
use Override;

/**
 * Special generator for application-assigned identifiers (doesn't really generate anything).
 */
class AssignedGenerator extends AbstractIdGenerator
{
    /**
     * Returns the identifier assigned to the given entity.
     *
     * {@inheritDoc}
     *
     * @throws EntityMissingAssignedId
     */
    #[Override]
    public function generateId(EntityManagerInterface $em, object|null $entity): array
    {
        $class      = $em->getClassMetadata($entity::class);
        $idFields   = $class->getIdentifierFieldNames();
        $identifier = [];

        foreach ($idFields as $idField) {
            $value = $class->getFieldValue($entity, $idField);

            if (! isset($value)) {
                throw EntityMissingAssignedId::forField($entity, $idField);
            }

            if (isset($class->associationMappings[$idField])) {
                // NOTE: Single Columns as associated identifiers only allowed - this constraint it is enforced.
                $value = $em->getUnitOfWork()->getSingleIdentifierValue($value);
            }

            $identifier[$idField] = $value;
        }

        return $identifier;
    }
}
