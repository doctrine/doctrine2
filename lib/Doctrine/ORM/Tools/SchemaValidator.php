<?php

declare(strict_types=1);

namespace Doctrine\ORM\Tools;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

use function array_diff;
use function array_key_exists;
use function array_search;
use function array_values;
use function class_exists;
use function class_parents;
use function count;
use function implode;
use function in_array;

/**
 * Performs strict validation of the mapping schema
 *
 * @link        www.doctrine-project.com
 */
class SchemaValidator
{
    /** @var EntityManagerInterface */
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Checks the internal consistency of all mapping files.
     *
     * There are several checks that can't be done at runtime or are too expensive, which can be verified
     * with this command. For example:
     *
     * 1. Check if a relation with "mappedBy" is actually connected to that specified field.
     * 2. Check if "mappedBy" and "inversedBy" are consistent to each other.
     * 3. Check if "referencedColumnName" attributes are really pointing to primary key columns.
     *
     * @psalm-return array<string, list<string>>
     */
    public function validateMapping()
    {
        $errors  = [];
        $cmf     = $this->em->getMetadataFactory();
        $classes = $cmf->getAllMetadata();

        foreach ($classes as $class) {
            $ce = $this->validateClass($class);
            if ($ce) {
                $errors[$class->name] = $ce;
            }
        }

        return $errors;
    }

    /**
     * Validates a single class of the current.
     *
     * @return string[]
     * @psalm-return list<string>
     */
    public function validateClass(ClassMetadataInfo $class)
    {
        $ce  = [];
        $cmf = $this->em->getMetadataFactory();

        foreach ($class->fieldMappings as $fieldName => $mapping) {
            if (! Type::hasType($mapping['type'])) {
                $ce[] = "The field '" . $class->name . '#' . $fieldName . "' uses a non-existent type '" . $mapping['type'] . "'.";
            }
        }

        if ($class->isEmbeddedClass && count($class->associationMappings) > 0) {
            $ce[] = "Embeddable '" . $class->name . "' does not support associations";

            return $ce;
        }

        foreach ($class->associationMappings as $fieldName => $assoc) {
            if (! class_exists($assoc['targetEntity']) || $cmf->isTransient($assoc['targetEntity'])) {
                $ce[] = "The target entity '" . $assoc['targetEntity'] . "' specified on " . $class->name . '#' . $fieldName . ' is unknown or not an entity.';

                return $ce;
            }

            if ($assoc['mappedBy'] && $assoc['inversedBy']) {
                $ce[] = 'The association ' . $class . '#' . $fieldName . ' cannot be defined as both inverse and owning.';
            }

            $targetMetadata = $cmf->getMetadataFor($assoc['targetEntity']);

            if (isset($assoc['id']) && $targetMetadata->containsForeignIdentifier) {
                $ce[] = "Cannot map association '" . $class->name . '#' . $fieldName . ' as identifier, because ' .
                        "the target entity '" . $targetMetadata->name . "' also maps an association as identifier.";
            }

            if ($assoc['mappedBy']) {
                if ($targetMetadata->hasField($assoc['mappedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the owning side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' which is not defined as association, but as field.';
                }

                if (! $targetMetadata->hasAssociation($assoc['mappedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the owning side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' which does not exist.';
                } elseif ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] === null) {
                    $ce[] = 'The field ' . $class->name . '#' . $fieldName . ' is on the inverse side of a ' .
                            'bi-directional relationship, but the specified mappedBy association on the target-entity ' .
                            $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' does not contain the required ' .
                            "'inversedBy=\"" . $fieldName . "\"' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc['mappedBy']]['inversedBy'] !== $fieldName) {
                    $ce[] = 'The mappings ' . $class->name . '#' . $fieldName . ' and ' .
                            $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' are ' .
                            'inconsistent with each other.';
                }
            }

            if ($assoc['inversedBy']) {
                if ($targetMetadata->hasField($assoc['inversedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' which is not defined as association.';
                }

                if (! $targetMetadata->hasAssociation($assoc['inversedBy'])) {
                    $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' refers to the inverse side ' .
                            'field ' . $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' which does not exist.';
                } elseif ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] === null) {
                    $ce[] = 'The field ' . $class->name . '#' . $fieldName . ' is on the owning side of a ' .
                            'bi-directional relationship, but the specified mappedBy association on the target-entity ' .
                            $assoc['targetEntity'] . '#' . $assoc['mappedBy'] . ' does not contain the required ' .
                            "'inversedBy' attribute.";
                } elseif ($targetMetadata->associationMappings[$assoc['inversedBy']]['mappedBy'] !== $fieldName) {
                    $ce[] = 'The mappings ' . $class->name . '#' . $fieldName . ' and ' .
                            $assoc['targetEntity'] . '#' . $assoc['inversedBy'] . ' are ' .
                            'inconsistent with each other.';
                }

                // Verify inverse side/owning side match each other
                if (array_key_exists($assoc['inversedBy'], $targetMetadata->associationMappings)) {
                    $targetAssoc = $targetMetadata->associationMappings[$assoc['inversedBy']];
                    if ($assoc['type'] === ClassMetadataInfo::ONE_TO_ONE && $targetAssoc['type'] !== ClassMetadataInfo::ONE_TO_ONE) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is one-to-one, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc['inversedBy'] . ' has to be one-to-one as well.';
                    } elseif ($assoc['type'] === ClassMetadataInfo::MANY_TO_ONE && $targetAssoc['type'] !== ClassMetadataInfo::ONE_TO_MANY) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is many-to-one, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc['inversedBy'] . ' has to be one-to-many.';
                    } elseif ($assoc['type'] === ClassMetadataInfo::MANY_TO_MANY && $targetAssoc['type'] !== ClassMetadataInfo::MANY_TO_MANY) {
                        $ce[] = 'If association ' . $class->name . '#' . $fieldName . ' is many-to-many, then the inversed ' .
                                'side ' . $targetMetadata->name . '#' . $assoc['inversedBy'] . ' has to be many-to-many as well.';
                    }
                }
            }

            if ($assoc['isOwningSide']) {
                if ($assoc['type'] === ClassMetadataInfo::MANY_TO_MANY) {
                    $identifierColumns = $class->getIdentifierColumnNames();
                    foreach ($assoc['joinTable']['joinColumns'] as $joinColumn) {
                        if (! in_array($joinColumn['referencedColumnName'], $identifierColumns)) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' " .
                                "has to be a primary key column on the target entity class '" . $class->name . "'.";
                            break;
                        }
                    }

                    $identifierColumns = $targetMetadata->getIdentifierColumnNames();
                    foreach ($assoc['joinTable']['inverseJoinColumns'] as $inverseJoinColumn) {
                        if (! in_array($inverseJoinColumn['referencedColumnName'], $identifierColumns)) {
                            $ce[] = "The referenced column name '" . $inverseJoinColumn['referencedColumnName'] . "' " .
                                "has to be a primary key column on the target entity class '" . $targetMetadata->name . "'.";
                            break;
                        }
                    }

                    if (count($targetMetadata->getIdentifierColumnNames()) !== count($assoc['joinTable']['inverseJoinColumns'])) {
                        $ce[] = "The inverse join columns of the many-to-many table '" . $assoc['joinTable']['name'] . "' " .
                                "have to contain to ALL identifier columns of the target entity '" . $targetMetadata->name . "', " .
                                "however '" . implode(', ', array_diff($targetMetadata->getIdentifierColumnNames(), array_values($assoc['relationToTargetKeyColumns']))) .
                                "' are missing.";
                    }

                    if (count($class->getIdentifierColumnNames()) !== count($assoc['joinTable']['joinColumns'])) {
                        $ce[] = "The join columns of the many-to-many table '" . $assoc['joinTable']['name'] . "' " .
                                "have to contain to ALL identifier columns of the source entity '" . $class->name . "', " .
                                "however '" . implode(', ', array_diff($class->getIdentifierColumnNames(), array_values($assoc['relationToSourceKeyColumns']))) .
                                "' are missing.";
                    }
                } elseif ($assoc['type'] & ClassMetadataInfo::TO_ONE) {
                    $identifierColumns = $targetMetadata->getIdentifierColumnNames();
                    foreach ($assoc['joinColumns'] as $joinColumn) {
                        if (! in_array($joinColumn['referencedColumnName'], $identifierColumns)) {
                            $ce[] = "The referenced column name '" . $joinColumn['referencedColumnName'] . "' " .
                                    "has to be a primary key column on the target entity class '" . $targetMetadata->name . "'.";
                        }
                    }

                    if (count($identifierColumns) !== count($assoc['joinColumns'])) {
                        $ids = [];

                        foreach ($assoc['joinColumns'] as $joinColumn) {
                            $ids[] = $joinColumn['name'];
                        }

                        $ce[] = "The join columns of the association '" . $assoc['fieldName'] . "' " .
                                "have to match to ALL identifier columns of the target entity '" . $targetMetadata->name . "', " .
                                "however '" . implode(', ', array_diff($targetMetadata->getIdentifierColumnNames(), $ids)) .
                                "' are missing.";
                    }
                }
            }

            if (isset($assoc['orderBy']) && $assoc['orderBy'] !== null) {
                foreach ($assoc['orderBy'] as $orderField => $orientation) {
                    if (! $targetMetadata->hasField($orderField) && ! $targetMetadata->hasAssociation($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a foreign field ' .
                                $orderField . ' that is not a field on the target entity ' . $targetMetadata->name . '.';
                        continue;
                    }

                    if ($targetMetadata->isCollectionValuedAssociation($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a field ' .
                                $orderField . ' on ' . $targetMetadata->name . ' that is a collection-valued association.';
                        continue;
                    }

                    if ($targetMetadata->isAssociationInverseSide($orderField)) {
                        $ce[] = 'The association ' . $class->name . '#' . $fieldName . ' is ordered by a field ' .
                                $orderField . ' on ' . $targetMetadata->name . ' that is the inverse side of an association.';
                        continue;
                    }
                }
            }
        }

        if (! $class->isInheritanceTypeNone() && ! $class->isRootEntity() && array_search($class->name, $class->discriminatorMap) === false) {
            $ce[] = "Entity class '" . $class->name . "' is part of inheritance hierarchy, but is " .
                "not mapped in the root entity '" . $class->rootEntityName . "' discriminator map. " .
                'All subclasses must be listed in the discriminator map.';
        }

        foreach ($class->subClasses as $subClass) {
            if (! in_array($class->name, class_parents($subClass))) {
                $ce[] = "According to the discriminator map class '" . $subClass . "' has to be a child " .
                        "of '" . $class->name . "' but these entities are not related through inheritance.";
            }
        }

        return $ce;
    }

    /**
     * Checks if the Database Schema is in sync with the current metadata state.
     *
     * @return bool
     */
    public function schemaInSyncWithMetadata()
    {
        $schemaTool = new SchemaTool($this->em);

        $allMetadata = $this->em->getMetadataFactory()->getAllMetadata();

        return count($schemaTool->getUpdateSchemaSql($allMetadata, true)) === 0;
    }
}
