<?php

declare(strict_types=1);

namespace Doctrine\ORM\Utility;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMetadata;
use Doctrine\ORM\Mapping\MetadataCollection;
use Doctrine\ORM\UnitOfWork;
use function implode;
use function is_object;

/**
 * The IdentifierFlattener utility now houses some of the identifier manipulation logic from unit of work, so that it
 * can be re-used elsewhere.
 *
 * @internal do not use in your own codebase: no BC compliance on this class
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
     * @var MetadataCollection
     */
    private $metadatas;

    /**
     * Initializes a new IdentifierFlattener instance, bound to the given EntityManager.
     */
    public function __construct(UnitOfWork $unitOfWork, MetadataCollection $metadataCollection)
    {
        $this->unitOfWork = $unitOfWork;
        $this->metadatas  = $metadataCollection;
    }

    /**
     * convert foreign identifiers into scalar foreign key values to avoid object to string conversion failures.
     *
     * @param mixed[] $id
     *
     * @return mixed[]
     */
    public function flattenIdentifier(ClassMetadata $class, array $id)
    {
        $flatId = [];

        foreach ($class->identifier as $field) {
            $property = $class->getProperty($field);

            if ($property instanceof FieldMetadata) {
                $flatId[$field] = $id[$field];

                continue;
            }

            if (isset($id[$field]) && is_object($id[$field])) {
                /** @var ClassMetadata $targetClassMetadata */
                $targetClassMetadata  = $this->metadatas->get($property->getTargetEntity());
                $targetClassPersister = $this->unitOfWork->getEntityPersister($property->getTargetEntity());
                // @todo guilhermeblanco Bring this back:
                // $identifiers         = $this->unitOfWork->isInIdentityMap($id[$field])
                //     ? $this->unitOfWork->getEntityIdentifier($id[$field])
                //     : $targetClassPersister->getIdentifier($id[$field])
                // ;
                $identifiers = $targetClassPersister->getIdentifier($id[$field]);

                $associatedId = $this->flattenIdentifier($targetClassMetadata, $identifiers);

                $flatId[$field] = implode(' ', $associatedId);

                continue;
            }

            $associatedId = [];

            foreach ($property->getJoinColumns() as $joinColumn) {
                $associatedId[] = $id[$joinColumn->getColumnName()];
            }

            $flatId[$field] = implode(' ', $associatedId);
        }

        return $flatId;
    }
}
