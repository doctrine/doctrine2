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
 * A <tt>ComponentMetadata</tt> instance holds object-relational property mapping.
 *
 * @package Doctrine\ORM\Mapping
 * @since 3.0
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 */
abstract class ComponentMetadata
{
    /**
     * @var string
     */
    protected $className;

    /**
     * @var ComponentMetadata|null
     */
    protected $parent;

    /**
     * The ReflectionClass instance of the component class.
     *
     * @var \ReflectionClass|null
     */
    protected $reflectionClass;

    /**
     * @var CacheMetadata|null
     */
    protected $cache = null;

    /**
     * @var array<string, Property>
     */
    protected $declaredProperties = [];

    /**
     * ComponentMetadata constructor.
     *
     * @param string                 $className
     * @param ComponentMetadata|null $parent
     */
    public function __construct(string $className, ?ComponentMetadata $parent = null)
    {
        $this->className = $className;
        $this->parent    = $parent;
    }

    /**
     * @return string
     */
    public function getClassName() : string
    {
        return $this->className;
    }

    /**
     * @return ComponentMetadata|null
     */
    public function getParent() : ?ComponentMetadata
    {
        return $this->parent;
    }

    /**
     * @return \ReflectionClass|null
     */
    public function getReflectionClass() : ?\ReflectionClass
    {
        return $this->reflectionClass;
    }

    /**
     * @param CacheMetadata|null $cache
     *
     * @return void
     */
    public function setCache(?CacheMetadata $cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * @return CacheMetadata|null
     */
    public function getCache(): ?CacheMetadata
    {
        return $this->cache;
    }

    /**
     * @return \Iterator
     */
    public function getDeclaredPropertiesIterator() : \Iterator
    {
        return new \ArrayIterator($this->declaredProperties);
    }

    /**
     * @param Property $property
     *
     * @throws MappingException
     */
    public function addDeclaredProperty(Property $property)
    {
        $propertyName = $property->getName();

        if ($this->hasProperty($propertyName)) {
            throw MappingException::duplicateProperty($this->getClassName(), $this->getProperty($propertyName));
        }

        $property->setDeclaringClass($this);

        if ($this->reflectionClass) {
            $property->setReflectionProperty($this->reflectionClass->getProperty($propertyName));
        }

        $this->declaredProperties[$propertyName] = $property;
    }

    /**
     * @param string $propertyName
     *
     * @return bool
     */
    public function hasDeclaredProperty(string $propertyName) : bool
    {
        return isset($this->declaredProperties[$propertyName]);
    }

    /**
     * @return \Iterator
     */
    public function getPropertiesIterator() : \Iterator
    {
        $declaredPropertiesIterator = $this->getDeclaredPropertiesIterator();

        if (! $this->parent) {
            return $declaredPropertiesIterator;
        }

        $iterator = new \AppendIterator();

        $iterator->append($this->parent->getPropertiesIterator());
        $iterator->append($declaredPropertiesIterator);

        return $iterator;
    }

    /**
     * @param string $propertyName
     *
     * @return null|Property
     */
    public function getProperty(string $propertyName) : ?Property
    {
        if (isset($this->declaredProperties[$propertyName])) {
            return $this->declaredProperties[$propertyName];
        }

        if ($this->parent) {
            return $this->parent->getProperty($propertyName);
        }

        return null;
    }

    /**
     * @param string $propertyName
     *
     * @return bool
     */
    public function hasProperty(string $propertyName) : bool
    {
        if (isset($this->declaredProperties[$propertyName])) {
            return true;
        }

        return $this->parent && $this->parent->hasProperty($propertyName);
    }

    /**
     * @param string|null $className
     *
     * @return string|null null if the input value is null
     */
    public function fullyQualifiedClassName(?string $className) : ?string
    {
        if ($className === null || ! $this->reflectionClass) {
            return $className;
        }

        $namespaceName  = $this->reflectionClass->getNamespaceName();
        $finalClassName = ($className !== null && strpos($className, '\\') === false && $namespaceName)
            ? sprintf('%s\\%s', $namespaceName, $className)
            : $className
        ;

        return ltrim($finalClassName, '\\');
    }
}
