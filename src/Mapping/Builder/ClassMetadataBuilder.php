<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Builder;

use BackedEnum;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Builder Object for ClassMetadata
 *
 * @link        www.doctrine-project.com
 */
class ClassMetadataBuilder
{
    public function __construct(
        private readonly ClassMetadata $cm,
    ) {
    }

    public function getClassMetadata(): ClassMetadata
    {
        return $this->cm;
    }

    /**
     * Marks the class as mapped superclass.
     *
     * @return $this
     */
    public function setMappedSuperClass(): static
    {
        $this->cm->isMappedSuperclass = true;
        $this->cm->isEmbeddedClass    = false;

        return $this;
    }

    /**
     * Marks the class as embeddable.
     *
     * @return $this
     */
    public function setEmbeddable(): static
    {
        $this->cm->isEmbeddedClass    = true;
        $this->cm->isMappedSuperclass = false;

        return $this;
    }

    /**
     * Adds and embedded class
     *
     * @param class-string $class
     *
     * @return $this
     */
    public function addEmbedded(string $fieldName, string $class, string|false|null $columnPrefix = null): static
    {
        $this->cm->mapEmbedded(
            [
                'fieldName'    => $fieldName,
                'class'        => $class,
                'columnPrefix' => $columnPrefix,
            ],
        );

        return $this;
    }

    /**
     * Sets custom Repository class name.
     *
     * @return $this
     */
    public function setCustomRepositoryClass(string $repositoryClassName): static
    {
        $this->cm->setCustomRepositoryClass($repositoryClassName);

        return $this;
    }

    /**
     * Marks class read only.
     *
     * @return $this
     */
    public function setReadOnly(): static
    {
        $this->cm->markReadOnly();

        return $this;
    }

    /**
     * Sets the table name.
     *
     * @return $this
     */
    public function setTable(string $name): static
    {
        $this->cm->setPrimaryTable(['name' => $name]);

        return $this;
    }

    /**
     * Adds Index.
     *
     * @phpstan-param list<string> $columns
     *
     * @return $this
     */
    public function addIndex(array $columns, string $name): static
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
     * @phpstan-param list<string> $columns
     *
     * @return $this
     */
    public function addUniqueConstraint(array $columns, string $name): static
    {
        if (! isset($this->cm->table['uniqueConstraints'])) {
            $this->cm->table['uniqueConstraints'] = [];
        }

        $this->cm->table['uniqueConstraints'][$name] = ['columns' => $columns];

        return $this;
    }

    /**
     * Sets class as root of a joined table inheritance hierarchy.
     *
     * @return $this
     */
    public function setJoinedTableInheritance(): static
    {
        $this->cm->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_JOINED);

        return $this;
    }

    /**
     * Sets class as root of a single table inheritance hierarchy.
     *
     * @return $this
     */
    public function setSingleTableInheritance(): static
    {
        $this->cm->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);

        return $this;
    }

    /**
     * Sets the discriminator column details.
     *
     * @param class-string<BackedEnum>|null $enumType
     * @param array<string, mixed>          $options
     *
     * @return $this
     */
    public function setDiscriminatorColumn(
        string $name,
        string $type = 'string',
        int $length = 255,
        string|null $columnDefinition = null,
        string|null $enumType = null,
        array $options = [],
    ): static {
        $this->cm->setDiscriminatorColumn(
            [
                'name' => $name,
                'type' => $type,
                'length' => $length,
                'columnDefinition' => $columnDefinition,
                'enumType' => $enumType,
                'options' => $options,
            ],
        );

        return $this;
    }

    /**
     * Adds a subclass to this inheritance hierarchy.
     *
     * @return $this
     */
    public function addDiscriminatorMapClass(string $name, string $class): static
    {
        $this->cm->addDiscriminatorMapClass($name, $class);

        return $this;
    }

    /**
     * Sets deferred explicit change tracking policy.
     *
     * @return $this
     */
    public function setChangeTrackingPolicyDeferredExplicit(): static
    {
        $this->cm->setChangeTrackingPolicy(ClassMetadata::CHANGETRACKING_DEFERRED_EXPLICIT);

        return $this;
    }

    /**
     * Adds lifecycle event.
     *
     * @return $this
     */
    public function addLifecycleEvent(string $methodName, string $event): static
    {
        $this->cm->addLifecycleCallback($methodName, $event);

        return $this;
    }

    /**
     * Adds Field.
     *
     * @phpstan-param array<string, mixed> $mapping
     *
     * @return $this
     */
    public function addField(string $name, string $type, array $mapping = []): static
    {
        $mapping['fieldName'] = $name;
        $mapping['type']      = $type;

        $this->cm->mapField($mapping);

        return $this;
    }

    /**
     * Adds a dynamic properties container: an array property whose keys are mapped to columns.
     *
     * Fields and associations are stored in it by calling {@see FieldBuilder::inDynamicProperties()}
     * or {@see AssociationBuilder::inDynamicProperties()}, or with {@see addDynamicField()}.
     *
     * @return $this
     */
    public function addDynamicProperties(string $fieldName): static
    {
        $this->cm->mapDynamicProperties(['fieldName' => $fieldName]);

        return $this;
    }

    /**
     * Adds a field that is stored under its name in the given dynamic properties container.
     *
     * @phpstan-param array<string, mixed> $mapping
     *
     * @return $this
     */
    public function addDynamicField(string $container, string $name, string $type, array $mapping = []): static
    {
        $mapping['fieldName'] = $name;
        $mapping['type']      = $type;

        $this->cm->mapDynamicField($container, $mapping);

        return $this;
    }

    /**
     * Creates a field builder.
     */
    public function createField(string $name, string $type): FieldBuilder
    {
        return new FieldBuilder(
            $this,
            [
                'fieldName' => $name,
                'type'      => $type,
            ],
        );
    }

    /**
     * Creates an embedded builder.
     */
    public function createEmbedded(string $fieldName, string $class): EmbeddedBuilder
    {
        return new EmbeddedBuilder(
            $this,
            [
                'fieldName'    => $fieldName,
                'class'        => $class,
                'columnPrefix' => null,
            ],
        );
    }

    /**
     * Adds a simple many to one association, optionally with the inversed by field.
     */
    public function addManyToOne(
        string $name,
        string $targetEntity,
        string|null $inversedBy = null,
    ): ClassMetadataBuilder {
        $builder = $this->createManyToOne($name, $targetEntity);

        if ($inversedBy !== null) {
            $builder->inversedBy($inversedBy);
        }

        return $builder->build();
    }

    /**
     * Creates a ManyToOne Association Builder.
     *
     * Note: This method does not add the association, you have to call build() on the AssociationBuilder.
     */
    public function createManyToOne(string $name, string $targetEntity): AssociationBuilder
    {
        return new AssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::MANY_TO_ONE,
        );
    }

    /**
     * Creates a OneToOne Association Builder.
     */
    public function createOneToOne(string $name, string $targetEntity): AssociationBuilder
    {
        return new AssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::ONE_TO_ONE,
        );
    }

    /**
     * Adds simple inverse one-to-one association.
     */
    public function addInverseOneToOne(string $name, string $targetEntity, string $mappedBy): ClassMetadataBuilder
    {
        $builder = $this->createOneToOne($name, $targetEntity);
        $builder->mappedBy($mappedBy);

        return $builder->build();
    }

    /**
     * Adds simple owning one-to-one association.
     */
    public function addOwningOneToOne(
        string $name,
        string $targetEntity,
        string|null $inversedBy = null,
    ): ClassMetadataBuilder {
        $builder = $this->createOneToOne($name, $targetEntity);

        if ($inversedBy !== null) {
            $builder->inversedBy($inversedBy);
        }

        return $builder->build();
    }

    /**
     * Creates a ManyToMany Association Builder.
     */
    public function createManyToMany(string $name, string $targetEntity): ManyToManyAssociationBuilder
    {
        return new ManyToManyAssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::MANY_TO_MANY,
        );
    }

    /**
     * Adds a simple owning many to many association.
     */
    public function addOwningManyToMany(
        string $name,
        string $targetEntity,
        string|null $inversedBy = null,
    ): ClassMetadataBuilder {
        $builder = $this->createManyToMany($name, $targetEntity);

        if ($inversedBy !== null) {
            $builder->inversedBy($inversedBy);
        }

        return $builder->build();
    }

    /**
     * Adds a simple inverse many to many association.
     */
    public function addInverseManyToMany(string $name, string $targetEntity, string $mappedBy): ClassMetadataBuilder
    {
        $builder = $this->createManyToMany($name, $targetEntity);
        $builder->mappedBy($mappedBy);

        return $builder->build();
    }

    /**
     * Creates a one to many association builder.
     */
    public function createOneToMany(string $name, string $targetEntity): OneToManyAssociationBuilder
    {
        return new OneToManyAssociationBuilder(
            $this,
            [
                'fieldName'    => $name,
                'targetEntity' => $targetEntity,
            ],
            ClassMetadata::ONE_TO_MANY,
        );
    }

    /**
     * Adds simple OneToMany association.
     */
    public function addOneToMany(string $name, string $targetEntity, string $mappedBy): ClassMetadataBuilder
    {
        $builder = $this->createOneToMany($name, $targetEntity);
        $builder->mappedBy($mappedBy);

        return $builder->build();
    }
}
