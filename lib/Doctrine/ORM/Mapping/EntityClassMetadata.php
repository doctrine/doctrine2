<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

declare(strict_types=1);

namespace Doctrine\ORM\Mapping;

/**
 * Class EntityClassMetadata
 *
 * @package Doctrine\ORM\Mapping
 * @since 3.0
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 */
abstract class EntityClassMetadata extends ComponentMetadata
{
    /** @var string The name of the Entity */
    protected $entityName;

    /**
     * @var null|string The name of the custom repository class used for the entity class.
     */
    protected $customRepositoryClassName;

    /**
     * @var null|Property The field which is used for versioning in optimistic locking (if any).
     */
    protected $declaredVersion = null;

    /**
     * Whether this class describes the mapping of a read-only class.
     * That means it is never considered for change-tracking in the UnitOfWork.
     * It is a very helpful performance optimization for entities that are immutable,
     * either in your domain or through the relation database (coming from a view,
     * or a history table for example).
     *
     * @var boolean
     */
    protected $readOnly = false;

    /**
     * List of all sub-classes (descendants) metadata.
     *
     * @var array<SubClassMetadata>
     */
    protected $subClasses = [];

    /**
     * The named queries allowed to be called directly from Repository.
     *
     * @var array
     */
    protected $namedQueries = [];

    /**
     * READ-ONLY: The named native queries allowed to be called directly from Repository.
     *
     * A native SQL named query definition has the following structure:
     * <pre>
     * array(
     *     'name'               => <query name>,
     *     'query'              => <sql query>,
     *     'resultClass'        => <class of the result>,
     *     'resultSetMapping'   => <name of a SqlResultSetMapping>
     * )
     * </pre>
     *
     * @var array
     */
    protected $namedNativeQueries = [];

    /**
     * READ-ONLY: The mappings of the results of native SQL queries.
     *
     * A native result mapping definition has the following structure:
     * <pre>
     * array(
     *     'name'               => <result name>,
     *     'entities'           => array(<entity result mapping>),
     *     'columns'            => array(<column result mapping>)
     * )
     * </pre>
     *
     * @var array
     */
    protected $sqlResultSetMappings = [];

    /**
     * READ-ONLY: The registered lifecycle callbacks for entities of this class.
     *
     * @var array
     */
    protected $lifecycleCallbacks = [];

    /**
     * READ-ONLY: The registered entity listeners.
     *
     * @var array
     */
    protected $entityListeners = [];

    /**
     * READ-ONLY: The field names of all fields that are part of the identifier/primary key
     * of the mapped entity class.
     *
     * @var array
     */
    protected $identifier = [];

    /**
     * READ-ONLY: The primary table metadata.
     *
     * @var TableMetadata
     */
    protected $table;

    /**
     * MappedSuperClassMetadata constructor.
     *
     * @param string                 $className
     * @param ComponentMetadata|null $parent
     */
    public function __construct(string $className, ?ComponentMetadata $parent = null)
    {
        parent::__construct($className, $parent);

        $this->entityName = $className;
    }

    /**
     * @return string
     */
    public function getEntityName() : string
    {
        return $this->entityName;
    }

    /**
     * @param string $entityName
     */
    public function setEntityName(string $entityName)
    {
        $this->entityName = $entityName;
    }

    /**
     * @return null|string
     */
    public function getCustomRepositoryClassName() : ?string
    {
        return $this->customRepositoryClassName;
    }

    /**
     * @param null|string customRepositoryClassName
     */
    public function setCustomRepositoryClassName(?string $customRepositoryClassName)
    {
        $this->customRepositoryClassName = $customRepositoryClassName;
    }

    /**
     * @return Property|null
     */
    public function getDeclaredVersion() : ?Property
    {
        return $this->declaredVersion;
    }

    /**
     * @param Property $property
     */
    public function setDeclaredVersion(Property $property)
    {
        $this->declaredVersion = $property;
    }

    /**
     * @return Property|null
     */
    public function getVersion() : ?Property
    {
        /** @var ComponentMetadata|null $parent */
        $parent  = $this->parent;
        $version = $this->declaredVersion;

        if ($parent && ! $version) {
            $version = $parent->getVersion();
        }

        return $version;
    }

    /**
     * @return bool
     */
    public function isVersioned() : bool
    {
        return $this->getVersion() !== null;
    }

    /**
     * @param bool $readOnly
     */
    public function setReadOnly(bool $readOnly)
    {
        $this->readOnly = $readOnly;
    }

    /**
     * @return bool
     */
    public function isReadOnly() : bool
    {
        return $this->readOnly;
    }

    /**
     * @param SubClassMetadata $subClassMetadata
     *
     * @throws MappingException
     */
    public function addSubClass(SubClassMetadata $subClassMetadata)
    {
        $superClassMetadata = $this->getSuperClass();

        while ($superClassMetadata !== null) {
            if ($superClassMetadata->entityName === $subClassMetadata->entityName) {
                throw new MappingException(
                    sprintf(
                        'Circular inheritance mapping detected: "%s" have itself as superclass when extending "%s".',
                        $subClassMetadata->entityName,
                        $superClassMetadata->entityName
                    )
                );
            }

            $superClassMetadata->subClasses[] = $subClassMetadata;

            $superClassMetadata = $superClassMetadata->parent;
        }

        $this->subClasses[] = $subClassMetadata;
    }

    /**
     * @return bool
     */
    public function hasSubClasses() : bool
    {
        return count($this->subClasses) > 0;
    }

    /**
     * @return \Iterator
     */
    public function getSubClassIterator() : \Iterator
    {
        $iterator = new \AppendIterator();

        foreach ($this->subClasses as $subClassMetadata) {
            $iterator->append($subClassMetadata->getSubClassIterator());
        }

        $iterator->append(new \ArrayIterator($this->subClasses));

        return $iterator;
    }

    /**
     * Adds a named query.
     *
     * @param string $name
     * @param string $dqlQuery
     *
     * @throws MappingException
     */
    public function addNamedQuery(string $name, string $dqlQuery)
    {
        if (isset($this->namedQueries[$name])) {
            throw MappingException::duplicateQueryMapping($this->entityName, $name);
        }

        $this->namedQueries[$name] = $dqlQuery;
    }

    /**
     * Gets a named query.
     *
     * @param string $queryName The query name.
     *
     * @return string
     *
     * @throws MappingException
     */
    public function getNamedQuery($queryName) : string
    {
        if (! isset($this->namedQueries[$queryName])) {
            throw MappingException::queryNotFound($this->entityName, $queryName);
        }

        return $this->namedQueries[$queryName];
    }

    /**
     * Gets all named queries of the class.
     *
     * @return array
     */
    public function getNamedQueries() : array
    {
        return $this->namedQueries;
    }

    /**
     * Gets a named native query.
     *
     * @param string $queryName The native query name.
     *
     * @return array
     *
     * @throws MappingException
     *
     * @todo guilhermeblanco This should return an object instead
     */
    public function getNamedNativeQuery($queryName) : array
    {
        if (! isset($this->namedNativeQueries[$queryName])) {
            throw MappingException::queryNotFound($this->entityName, $queryName);
        }

        return $this->namedNativeQueries[$queryName];
    }

    /**
     * Gets all named native queries of the class.
     *
     * @return array
     */
    public function getNamedNativeQueries() : array
    {
        return $this->namedNativeQueries;
    }

    /**
     * Gets the result set mapping.
     *
     * @param string $name The result set mapping name.
     *
     * @return array
     *
     * @throws MappingException
     *
     * @todo guilhermeblanco This should return an object instead
     */
    public function getSqlResultSetMapping($name) : array
    {
        if (! isset($this->sqlResultSetMappings[$name])) {
            throw MappingException::resultMappingNotFound($this->entityName, $name);
        }

        return $this->sqlResultSetMappings[$name];
    }

    /**
     * Gets all sql result set mappings of the class.
     *
     * @return array
     */
    public function getSqlResultSetMappings() : array
    {
        return $this->sqlResultSetMappings;
    }

    /**
     * {@inheritdoc}
     */
    public function addDeclaredProperty(Property $property)
    {
        parent::addDeclaredProperty($property);

        if ($property instanceof VersionFieldMetadata) {
            $this->setDeclaredVersion($property);
        }
    }

    /**
     * @return RootClassMetadata
     */
    abstract public function getRootClass() : RootClassMetadata;
}
