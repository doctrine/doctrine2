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

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Reflection\ReflectionService;
use Doctrine\ORM\Sequencing\Generator;

class FieldMetadata extends LocalColumnMetadata implements Property
{
    /** @var ClassMetadata */
    protected $declaringClass;

    /** @var \ReflectionProperty */
    protected $reflection;

    /** @var string */
    protected $name;

    /**
     * FieldMetadata constructor.
     *
     * @param string $name
     * @param string $columnName
     * @param Type   $type
     *
     * @todo Leverage this implementation instead of default, simple constructor
     */
    /*public function __construct(string $name, string $columnName, Type $type)
    {
        parent::__construct($columnName, $type);

        $this->name = $name;
    }*/

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * {@inheritdoc}
     */
    public function getDeclaringClass()
    {
        return $this->declaringClass;
    }

    /**
     * @param ClassMetadata $declaringClass
     */
    public function setDeclaringClass(ClassMetadata $declaringClass)
    {
        $this->declaringClass = $declaringClass;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function setValue($object, $value)
    {
        $this->reflection->setValue($object, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getValue($object)
    {
        return $this->reflection->getValue($object);
    }

    /**
     * {@inheritdoc}
     */
    public function isAssociation()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isField()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setReflectionProperty(\ReflectionProperty $reflectionProperty)
    {
        $this->reflection = $reflectionProperty;
    }

    /**
     * {@inheritdoc}
     */
    public function wakeupReflection(ReflectionService $reflectionService)
    {
        $this->setReflectionProperty(
            $reflectionService->getAccessibleProperty($this->declaringClass->getClassName(), $this->name)
        );
    }
}
