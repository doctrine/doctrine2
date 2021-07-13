<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataInfo;

/**
 * Builder Object for ClassMetadata
 *
 * @link        www.doctrine-project.com
 */
class ClassMetadataBuilder
{
    /** @var ClassMetadataInfo */
    private $cm;

    public function __construct(ClassMetadataInfo $cm)
    {
        $this->cm = $cm;
    }

    /**
     * @return ClassMetadataInfo
     */
    public function getClassMetadata()
    {
        return $this->cm;
    }

    /**
     * Marks the class as mapped superclass.
     *
     * @return static
     */
    public function setMappedSuperClass()
    {
        $this->cm->isMappedSuperclass = true;
        $this->cm->isEmbeddedClass    = false;

        return $this;
    }

    /**
     * Marks the class as embeddable.
     *
     * @return static
     */
    public function setEmbeddable()
    {
        $this->cm->isEmbeddedClass    = true;
        $this->cm->isMappedSuperclass = false;

        return $this;
    }

    /**
     * Adds and embedded class
     *
     * @param string      $fieldName
     * @param string      $class
     * @param string|null $columnPrefix
     *
     * @return $this
     */
    public function addEmbedded($fieldName, $class, $columnPrefix = null)
    {
        $this->cm->mapEmbedded(
            [
                'fieldName'    => $fieldName,
                'class'        => $class,
                'columnPrefix' => $columnPrefix,
            ]
        );

        return $this;
    }

    /**
     * Sets custom Repository class name.
     *
     * @param string $repositoryClassName
     *
     * @return static
     */
    public function setCustomRepositoryClass($repositoryClassName)
    {
        $this->cm->setCustomRepositoryClass($repositoryClassName);

        return $this;
    }

    /**
     * Marks class read only.
     *
     * @return static
     */
    public function setReadOnly()
    {
        $this->cm->markReadOnly();

        return $this;
    }

    /**
     * Sets the table name.
     *
     * @param string $name
     *
     * @return static
     */
    public function setTable($name)
    {
        $this->cm->setPrimaryTable(['name' => $name]);

        return $this;
    }

    /**
     * Adds Index.
     *
     * @param string $name
     * @psalm-param list<string> $columns
     *
     * @return static
     */
    public function addIndex(array $columns, $name)
    {
        if (! isset($this->cm->table['indexes'])) {
            $this->cm->table['indexes'] = [];
        }

        $this->cm->table['indexes'][$name] = ['columns' => $columns];

        return $this;
    }

    /**
     * Adds Unique Constraint.
     *
     * @param string $name
     * @psalm-param list<string> $columns
     *
     * @return static
     */
    public function addUniqueConstraint(array $columns, $name)
    {
        if (! isset($this->cm->table['uniqueConstraints'])) {
            $this->cm->table['uniqueConstraints'] = [];
        }

        $this->cm->table['uniqueConstraints'][$name] = ['columns' => $columns];

        return $this;
    }

    /**
     * Adds named query.
     *
     * @param string $name
     * @param string $dqlQuery
     *
     * @return static
     */
    public function addNamedQuery($name, $dqlQuery)
    {
        $this->cm->addNamedQuery(
            [
                'name' => $name,
                'query' => $dqlQuery,
            ]
        );

        return $this;
    }

    /**
     * Sets class as root of a joined table inheritance hierarchy.
     *
     * @return static
     */
    public function setJoinedTableInheritance()
    {
        $this->cm->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_JOINED);

        return $this;
    }

    /**
     * Sets class as root of a single table inheritance hierarchy.
     *
     * @return static
     */
    public function setSingleTableInheritance()
    {
        $this->cm->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);

        return $this;
    }

    /**
     * Sets the discriminator column details.
     *
     * @param string $name
     * @param string $type
     * @param int    $length
     *
     * @return static
     */
    public function setDiscriminatorColumn($name, $type = 'string', $length = 255)
    {
        $this->cm->setDiscriminatorColumn(
            [
                'name' => $name,
                'type' => $type,
                'length' => $length,
            ]
        );

        return $this;
    }

    /**
     * Adds a subclass to this inheritance hierarchy.
     *
     * @param string $name
     * @param string $class
     *
     * @return static
     */
    public function addDiscriminatorMapClass($name, $class)
    {
        $this->cm->addDiscriminatorMapClass($name, $class);

        return $this;
    }

    /**
     * Sets deferred explicit change tracking policy.
     *
     * @return static
     */
    public function setChangeTrackingPolicyDeferredExplicit()
    {
        $this->cm->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT);

        return $this;
    }

    /**
     * Sets notify change tracking policy.
     *
     * @return static
     */
    public function setChangeTrackingPolicyNotify()
    {
        $this->cm->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_NOTIFY);

        return $this;
    }

    /**
     * Adds lifecycle event.
     *
     * @param string $methodName
     * @param string $event
     *
     * @return static
     */
    public function addLifecycleEvent($methodName, $event)
    {
        $this->cm->addLifecycleCallback($methodName, $event);

        return $this;
    }

    /**
     * Adds Field.
     *
     * @param string $name
     * @param string $type
     * @psalm-param array<string, mixed> $mapping
     *
     * @return static
     */
    public function addField($name, $type, array $mapping = [])
    {
        $mapping['fieldName'] = $name;
        $mapping['type']      = $type;

        $this->cm->mapField($mapping);

        return $this;
    }

    /**
     * Creates a field builder.
     *
     * @param string $name
     * @param string $type
     *
     * @return FieldBuilder
     */
    public function createField($name, $type)
    {
        return new FieldBuilder(
            $this,
            [
                'fieldName' => $name,
                'type'      => $type,
            ]
        );
    }

    /**
     * Creates an embedded builder.
     *
     * @param string $fieldName
     * @param string $class
     *
     * @return EmbeddedBuilder
     */
    public function createEmbedded($fieldName, $class)
    {
        return new EmbeddedBuilder(
            $this,
            [
                'fieldName'    => $fieldName,
                'class'        => $class,
                'columnPrefix' => null,
            ]
        );
    }

    /**
     * Adds a simple many to one association, optionally with the inversed by field.
     *
     * @param string      $name
     * @param string      $targetEntity
     * @param string|null $inversedBy
     *
     * @return ClassMetadataBuilder
     */
    public function addManyToOne($name, $targetEntity, $inversedBy = null)
    {
        $builder = $this->createManyToOne($name, $targetEntity);

        if ($inversedBy) {
            $builder->inversedBy($inversedBy);
        }

        return $builder->build();
    }

    /**
     * Creates a ManyToOne Association Builder.
     *
     * Note: This method does not add the association, you have to call build() on the AssociationBuilder.
     *
     * @param string $name
     * @param string $targetEntity
     *
     * @return AssociationBuilder
     */
    public function createManyToOne($name, $targetEntity)
    {
        return new AssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::MANY_TO_ONE
        );
    }

    /**
     * Creates a OneToOne Association Builder.
     *
     * @param string $name
     * @param string $targetEntity
     *
     * @return AssociationBuilder
     */
    public function createOneToOne($name, $targetEntity)
    {
        return new AssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::ONE_TO_ONE
        );
    }

    /**
     * Adds simple inverse one-to-one association.
     *
     * @param string $name
     * @param string $targetEntity
     * @param string $mappedBy
     *
     * @return ClassMetadataBuilder
     */
    public function addInverseOneToOne($name, $targetEntity, $mappedBy)
    {
        $builder = $this->createOneToOne($name, $targetEntity);
        $builder->mappedBy($mappedBy);

        return $builder->build();
    }

    /**
     * Adds simple owning one-to-one association.
     *
     * @param string      $name
     * @param string      $targetEntity
     * @param string|null $inversedBy
     *
     * @return ClassMetadataBuilder
     */
    public function addOwningOneToOne($name, $targetEntity, $inversedBy = null)
    {
        $builder = $this->createOneToOne($name, $targetEntity);

        if ($inversedBy) {
            $builder->inversedBy($inversedBy);
        }

        return $builder->build();
    }

    /**
     * Creates a ManyToMany Association Builder.
     *
     * @param string $name
     * @param string $targetEntity
     *
     * @return ManyToManyAssociationBuilder
     */
    public function createManyToMany($name, $targetEntity)
    {
        return new ManyToManyAssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::MANY_TO_MANY
        );
    }

    /**
     * Adds a simple owning many to many association.
     *
     * @param string      $name
     * @param string      $targetEntity
     * @param string|null $inversedBy
     *
     * @return ClassMetadataBuilder
     */
    public function addOwningManyToMany($name, $targetEntity, $inversedBy = null)
    {
        $builder = $this->createManyToMany($name, $targetEntity);

        if ($inversedBy) {
            $builder->inversedBy($inversedBy);
        }

        return $builder->build();
    }

    /**
     * Adds a simple inverse many to many association.
     *
     * @param string $name
     * @param string $targetEntity
     * @param string $mappedBy
     *
     * @return ClassMetadataBuilder
     */
    public function addInverseManyToMany($name, $targetEntity, $mappedBy)
    {
        $builder = $this->createManyToMany($name, $targetEntity);
        $builder->mappedBy($mappedBy);

        return $builder->build();
    }

    /**
     * Creates a one to many association builder.
     *
     * @param string $name
     * @param string $targetEntity
     *
     * @return OneToManyAssociationBuilder
     */
    public function createOneToMany($name, $targetEntity)
    {
        return new OneToManyAssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::ONE_TO_MANY
        );
    }

    /**
     * Adds simple OneToMany association.
     *
     * @param string $name
     * @param string $targetEntity
     * @param string $mappedBy
     *
     * @return ClassMetadataBuilder
     */
    public function addOneToMany($name, $targetEntity, $mappedBy)
    {
        $builder = $this->createOneToMany($name, $targetEntity);
        $builder->mappedBy($mappedBy);

        return $builder->build();
    }
}
