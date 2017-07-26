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

namespace Doctrine\ORM\Proxy\Factory;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Proxy\Proxy;

/**
 * This factory is used to generate proxy classes.
 * It builds proxies from given parameters, a template and class metadata.
 *
 * @author Guilherme Blanco <guilhermeblanco@hotmail.com>
 * @author Marco Pivetta <ocramius@gmail.com>
 * @since  2.4
 */
class ProxyGenerator
{
    /**
     * Used to match very simple id methods that don't need
     * to be decorated since the identifier is known.
     */
    const PATTERN_MATCH_ID_METHOD = '((public\s+)?(function\s+%s\s*\(\)\s*)\s*(?::\s*\??\s*\\\\?[a-z_\x7f-\xff][\w\x7f-\xff]*(?:\\\\[a-z_\x7f-\xff][\w\x7f-\xff]*)*\s*)?{\s*return\s*\$this->%s;\s*})i';

    /**
     * Map of callables used to fill in placeholders set in the template.
     *
     * @var string[]|callable[]
     */
    protected $placeholders = [
        'baseProxyInterface'   => Proxy::class,
        'additionalProperties' => '',
    ];

    /**
     * Template used as a blueprint to generate proxies.
     *
     * @var string
     */
    protected $proxyClassTemplate = '<?php

namespace <namespace>;

use Doctrine\ORM\Proxy\Factory\ProxyDefinition;

/**
 * DO NOT EDIT THIS FILE - IT WAS CREATED BY DOCTRINE\'S PROXY GENERATOR
 */
class <proxyShortClassName> extends \<className> implements \<baseProxyInterface>
{
    /**
     * @static
     * @var array Cache for an array of proxy managed public properties
     */
    static private $__lazyPublicPropertyList__ = <lazyPublicPropertyList>;

    /**
     * @var ProxyDefinition
      
     * @see \Doctrine\ORM\Proxy::__setProxyDefinition
     */
    private $__proxyDefinition__;

    /**
     * @var boolean flag indicating if this object was already initialized
     *
     * @see \Doctrine\ORM\Proxy::__isInitialized
     */
    private $__isInitialized__ = false;

<additionalProperties>

    /**
     * @param ProxyDefinition $definition
     */
    public function __construct(ProxyDefinition $definition)
    {
        $this->__proxyDefinition__ = $definition;
        
        foreach (static::$__lazyPublicPropertyList__ as $propertyName => $defaultValue) {
            unset($this->$propertyName);
        }
    }
    
    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __isInitialized()
    {
        return $this->__isInitialized__;
    }

    /**
     * {@inheritDoc}
     * @internal generated method: use only when explicitly handling proxy specific loading logic
     */
    public function __setInitialized($initialized)
    {
        $this->__isInitialized__ = $initialized;
    }

    /**
     * Clones the proxy
     */
    public function __clone()
    {
        ! $this->__isInitialized__ && $this->__proxyDefinition__->cloner($this);
        
        if (is_callable("parent::__clone")) {
            parent::__clone();
        }
    }

    /**
     * Forces initialization of the proxy
     */
    public function __load()
    {
        ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, "__load", []);
        
        if (is_callable("parent::__load")) {
            parent::__load();
        }
    }

<magicSleep>
    
<magicWakeup>

<magicGet>

<magicSet>

<magicIsset>

<methods>
}
';

    /**
     * Sets a placeholder to be replaced in the template.
     *
     * @param string          $name
     * @param string|callable $placeholder
     *
     * @throws \InvalidArgumentException
     */
    public function setPlaceholder($name, $placeholder)
    {
        if (! is_string($placeholder) && ! is_callable($placeholder)) {
            throw new \InvalidArgumentException(
                sprintf('Provided placeholder for "%s" must be either a string or a valid callable', $name)
            );
        }

        $this->placeholders[$name] = $placeholder;
    }

    /**
     * Sets the base template used to create proxy classes.
     *
     * @param string $proxyClassTemplate
     */
    public function setProxyClassTemplate($proxyClassTemplate)
    {
        $this->proxyClassTemplate = (string) $proxyClassTemplate;
    }

    /**
     * Generates proxy class code.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    public function generate(ProxyDefinition $definition) : string
    {
        $this->verifyClassCanBeProxied($definition->entityClassMetadata);

        return $this->renderTemplate($this->proxyClassTemplate, $definition, $this->placeholders);
    }

    /**
     * @param ClassMetadata $class
     *
     * @throws \InvalidArgumentException
     */
    private function verifyClassCanBeProxied(ClassMetadata $class)
    {
        $reflectionClass = $class->getReflectionClass();

        if ($reflectionClass->isFinal()) {
            throw new \InvalidArgumentException(
                sprintf('Unable to create a proxy for a final class "%s".', $reflectionClass->getName())
            );
        }

        if ($reflectionClass->isAbstract()) {
            throw new \InvalidArgumentException(
                sprintf('Unable to create a proxy for an abstract class "%s".', $reflectionClass->getName())
            );
        }
    }

    /**
     * Generates the proxy short class name to be used in the template.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateProxyShortClassName(ProxyDefinition $definition) : string
    {
        $parts = explode('\\', strrev($definition->proxyClassName), 2);

        return strrev($parts[0]);
    }

    /**
     * Generates the proxy namespace.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateNamespace(ProxyDefinition $definition) : string
    {
        $parts = explode('\\', strrev($definition->proxyClassName), 2);

        return strrev($parts[1]);
    }

    /**
     * Generates the original class name.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateClassName(ProxyDefinition $definition) : string
    {
        return $definition->entityClassMetadata->getClassName();
    }

    private function generateLazyPublicPropertyList(ProxyDefinition $definition) : string
    {
        $lazyPublicProperties = $definition->getLazyPublicPropertyList();

        return var_export($lazyPublicProperties, true);
    }

    /**
     * Generates the magic wakeup invoked when unserialize is called.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateMagicWakeup(ProxyDefinition $definition) : string
    {
        $lazyPublicProperties = $definition->getLazyPublicPropertyList();
        $reflectionClass      = $definition->entityClassMetadata->getReflectionClass();
        $hasParentWakeup      = $reflectionClass->hasMethod('__wakeup');

        if (empty($lazyPublicProperties) && ! $hasParentWakeup) {
            return '';
        }

        if ($hasParentWakeup) {
            return <<<'EOT'
    /**
     * {@inheritDoc}
     */
    public function __wakeup()
    {
        if (! $this->__isInitialized__) {
            foreach (static::$__lazyPublicPropertyList__ as $propertyName => $defaultValue) {
                unset($this->$propertyName);
            }
        }
        
        parent::__wakeup();
    }
EOT;
        }

        return <<<'EOT'
    /**
     * Provides deserialization support for the proxy
     */
    public function __wakeup()
    {
        if (! $this->__isInitialized__) {
            foreach (static::$__lazyPublicPropertyList__ as $propertyName => $defaultValue) {
                unset($this->$propertyName);
            }
        }
    }
EOT;
    }

    private function generateMagicSleep(ProxyDefinition $definition) : string
    {
        $reflectionClass = $definition->entityClassMetadata->getReflectionClass();
        $hasParentSleep  = $reflectionClass->hasMethod('__sleep');

        if ($hasParentSleep) {
            return <<<'EOT'
    /**
     * {@inheritDoc}
     */
    public function __sleep()
    {
        $allProperties = array_merge(["__isInitialized__"], parent::__sleep());
        
        return ! $this->__isInitialized__
            ? array_diff($allProperties, array_keys(static::$__lazyPublicPropertyList__))
            : $allProperties
        ;
    }
EOT;
        }

        return <<<'EOT'
    /**
     * Provides serialization support for the proxy
     */
    public function __sleep()
    {
        $classMetadata   = $this->__proxyDefinition__->entityClassMetadata;
        $reflectionClass = $classMetadata->getReflectionClass();
        $allProperties   = ["__isInitialized__"];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if ($reflectionProperty->isStatic()) {
                continue;
            }
            
            $propertyPrefix = $reflectionProperty->isPrivate()
                ? "\0" . $reflectionProperty->getDeclaringClass()->getName() . "\0"
                : ""
            ;
        
            $allProperties[] = $propertyPrefix . $reflectionProperty->getName();
        }
        
        return ! $this->__isInitialized__
            ? array_diff($allProperties, array_keys(static::$__lazyPublicPropertyList__))
            : $allProperties
        ;
    }
EOT;
    }

    /**
     * Generates the magic getter invoked when lazy loaded public properties are requested.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateMagicGet(ProxyDefinition $definition) : string
    {
        $lazyPublicProperties = $definition->getLazyPublicPropertyList();
        $reflectionClass      = $definition->entityClassMetadata->getReflectionClass();
        $hasParentGet         = $reflectionClass->hasMethod('__get');

        if (empty($lazyPublicProperties) && ! $hasParentGet) {
            return '';
        }

        if ($hasParentGet) {
            $returnReference = $reflectionClass->getMethod('__get')->returnsReference() ? '& ' : '';

            $magicGet = <<<'EOT'
    /**
     * {@inheritDoc}
     */
    public function <returnReference>__get($name)
    {
        ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, '__get', [$name]);
        
        return parent::__get($name);
    }
EOT;

            return $this->renderTemplate($magicGet, $definition, [
                'returnReference' => $returnReference,
            ]);
        }

        return <<<'EOT'
    /**
     * Provides property retrieval support for the proxy
     */
    public function __get($name)
    {
        if (static::$__lazyPublicPropertyList__ && array_key_exists($name, static::$__lazyPublicPropertyList__)) {
            ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, '__get', [$name]);

            return $this->$name;
        }

        trigger_error(sprintf('Undefined property: %s::$%s', __CLASS__, $name), E_USER_NOTICE);
    }
EOT;
    }

    /**
     * Generates the magic setter (currently unused).
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateMagicSet(ProxyDefinition $definition) : string
    {
        $lazyPublicProperties = $definition->getLazyPublicPropertyList();
        $reflectionClass      = $definition->entityClassMetadata->getReflectionClass();
        $hasParentSet         = $reflectionClass->hasMethod('__set');

        if (empty($lazyPublicProperties) && ! $hasParentSet) {
            return '';
        }

        if ($hasParentSet) {
            return <<<'EOT'
    /**
     * {@inheritDoc}
     */
    public function __set($name, $value)
    {
        ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, '__set', [$name, $value]);
        
        return parent::__set($name, $value);
    }
EOT;
        }

        return <<<'EOT'
    /**
     * Provides property accessor support for the proxy
     */
    public function __set($name, $value)
    {
        if (static::$__lazyPublicPropertyList__ && array_key_exists($name, static::$__lazyPublicPropertyList__)) {
            ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, '__set', [$name, $value]);
        }
        
        $this->$name = $value;
    }
EOT;
    }

    /**
     * Generates the magic issetter invoked when lazy loaded public properties are checked against isset().
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateMagicIsset(ProxyDefinition $definition) : string
    {
        $lazyPublicProperties = $definition->getLazyPublicPropertyList();
        $reflectionClass      = $definition->entityClassMetadata->getReflectionClass();
        $hasParentIsset       = $reflectionClass->hasMethod('__isset');

        if (empty($lazyPublicProperties) && ! $hasParentIsset) {
            return '';
        }

        if ($hasParentIsset) {
            return <<<'EOT'
    /**
     * {@inheritDoc}
     */
    public function __isset($name)
    {
        ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, '__isset', [$name]);
        
        return parent::__isset($name);
    }
EOT;
        }

        return <<<'EOT'
    /**
     * Provide property checker for the proxy
     */
    public function __isset($name)
    {
        if (static::$__lazyPublicPropertyList__ && array_key_exists($name, static::$__lazyPublicPropertyList__)) {
            ! $this->__isInitialized__ &&$this->__proxyDefinition__->initializer($this, '__isset', [$name]);
            
            return isset($this->$name);
        }
        
        return false;
    }
EOT;
    }

    /**
     * Generates decorated methods by picking those available in the parent class.
     *
     * @param ProxyDefinition $definition
     *
     * @return string
     */
    private function generateMethods(ProxyDefinition $definition) : string
    {
        $classMetadata     = $definition->entityClassMetadata;
        $reflectionClass   = $classMetadata->getReflectionClass();
        $reflectionMethods = $reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC);
        $filteredMethods   = array_filter($reflectionMethods, function (\ReflectionMethod $reflectionMethod) use ($classMetadata) {
            // Do not consider static or constructor
            if ($reflectionMethod->isConstructor() || $reflectionMethod->isStatic()) {
                return false;
            }

            // Do not consider non-visible (or overloadable) methods
            if ($reflectionMethod->isFinal() || ! $reflectionMethod->isPublic()) {
                return false;
            }

            // Do not consider identifier-getter methods
            $fieldCandidate = lcfirst(substr($reflectionMethod->getName(), 3));
            $isIdentifier   = $reflectionMethod->getNumberOfParameters() === 0
                && strpos($reflectionMethod->getName(), 'get') === 0
                && $classMetadata->hasField($fieldCandidate)
                && in_array($fieldCandidate, $classMetadata->getIdentifier(), true)
            ;

            if ($isIdentifier) {
                return false;
            }

            // Do not consider magic methods
            $skippedMethods    = [
                '__clone'   => true,
                '__get'     => true,
                '__isset'   => true,
                '__set'     => true,
                '__sleep'   => true,
                '__wakeup'  => true,
            ];

            return ! isset($skippedMethods[strtolower($reflectionMethod->getName())]);
        });

        $methodList     = [];

        /** @var \ReflectionMethod $reflectionMethod */
        foreach ($filteredMethods as $reflectionMethod) {
            $methodName       = $reflectionMethod->getName();
            $methodParameters = $reflectionMethod->getParameters();

            $parameterList           = $this->buildParametersString($reflectionMethod, $methodParameters);
            $invocationParameterList = implode(', ', $this->getParameterNamesForInvocation($methodParameters));

            $returnReference = $reflectionMethod->returnsReference() ? '& ' : '';
            $returnType      = $this->getMethodReturnType($reflectionMethod);
            $returnValue     = $this->doesMethodReturnValue($reflectionMethod) ? 'return ' : '';

            $methodTemplate = <<<'EOT'
    /**
     * {@inheritdoc}
     */
    public function <returnReference><methodName>(<parameterList>)<returnType>
    {
        ! $this->__isInitialized__ && $this->__proxyDefinition__->initializer($this, __METHOD__, [<invocationParameterList>]);
        
        <returnValue>parent::<methodName>(<invocationParameterList>);
    }
EOT;

            $methodList[] = $this->renderTemplate($methodTemplate, $definition, [
                'methodName'              => $methodName,
                'parameterList'           => $parameterList,
                'invocationParameterList' => $invocationParameterList,
                'returnReference'         => $returnReference,
                'returnType'              => $returnType,
                'returnValue'             => $returnValue,
            ]);
        }

        return implode("\n\n", $methodList);
    }

    /**
     * @param \ReflectionMethod      $method
     * @param \ReflectionParameter[] $parameters
     *
     * @return string
     */
    private function buildParametersString(\ReflectionMethod $method, array $parameters)
    {
        $parameterDefinitions = [];

        /* @var $param \ReflectionParameter */
        foreach ($parameters as $param) {
            $parameterDefinition = '';

            if ($parameterType = $this->getParameterType($method, $param)) {
                $parameterDefinition .= $parameterType . ' ';
            }

            if ($param->isPassedByReference()) {
                $parameterDefinition .= '&';
            }

            if ($param->isVariadic()) {
                $parameterDefinition .= '...';
            }

            $parameterDefinition .= '$' . $param->getName();

            if ($param->isDefaultValueAvailable()) {
                $parameterDefinition .= ' = ' . var_export($param->getDefaultValue(), true);
            }

            $parameterDefinitions[] = $parameterDefinition;
        }

        return implode(', ', $parameterDefinitions);
    }

    /**
     * @param \ReflectionMethod $method
     * @param \ReflectionParameter $parameter
     *
     * @return string|null
     */
    private function getParameterType(\ReflectionMethod $method, \ReflectionParameter $parameter)
    {
        if (method_exists($parameter, 'hasType')) {
            if (! $parameter->hasType()) {
                return '';
            }

            return $this->formatType($parameter->getType(), $parameter->getDeclaringFunction(), $parameter);
        }

        // For PHP 5.x, we need to pick the type hint in the old way (to be removed for PHP 7.0+)
        if ($parameter->isArray()) {
            return 'array';
        }

        if ($parameter->isCallable()) {
            return 'callable';
        }

        try {
            $parameterClass = $parameter->getClass();

            if ($parameterClass) {
                return '\\' . $parameterClass->getName();
            }
        } catch (\ReflectionException $previous) {
            throw new \UnexpectedValueException(
                sprintf(
                    'The type hint of parameter "%s" in method "%s" in class "%s" is invalid.',
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $parameter->getName()
                ),
                0,
                $previous
            );
        }

        return null;
    }

    /**
     * @param \ReflectionParameter[] $parameters
     *
     * @return string[]
     */
    private function getParameterNamesForInvocation(array $parameters)
    {
        return array_map(
            function (\ReflectionParameter $parameter) {
                $name = '';

                if ($parameter->isVariadic()) {
                    $name .= '...';
                }

                $name .= '$' . $parameter->getName();

                return $name;
            },
            $parameters
        );
    }

    /**
     * @param \ReflectionMethod $method
     *
     * @return string
     */
    private function getMethodReturnType(\ReflectionMethod $method)
    {
        return $method->hasReturnType()
            ? ': ' . $this->formatType($method->getReturnType(), $method)
            : ''
        ;
    }

    /**
     * @param \ReflectionMethod $method
     *
     * @return bool
     */
    private function doesMethodReturnValue(\ReflectionMethod $method)
    {
        return $method->hasReturnType()
            ? 'void' !== strtolower($this->formatType($method->getReturnType(), $method))
            : true
        ;
    }

    /**
     * @param \ReflectionType $type
     * @param \ReflectionMethod $method
     * @param \ReflectionParameter|null $parameter
     *
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    private function formatType(
        \ReflectionType $type,
        \ReflectionMethod $method,
        \ReflectionParameter $parameter = null
    ) {
        $name = method_exists($type, 'getName') ? $type->getName() : (string) $type;
        $nameLower = strtolower($name);

        if ('self' === $nameLower) {
            $name = $method->getDeclaringClass()->getName();
        }

        if ('parent' === $nameLower) {
            $name = $method->getDeclaringClass()->getParentClass()->getName();
        }

        if ( ! $type->isBuiltin() && ! class_exists($name) && ! interface_exists($name)) {
            if (null !== $parameter) {
                throw new \UnexpectedValueException(
                    sprintf(
                        'The type hint of parameter "%s" in method "%s" in class "%s" is invalid.',
                        $method->getDeclaringClass()->getName(),
                        $method->getName(),
                        $parameter->getName()
                    )
                );
            }

            throw new \UnexpectedValueException(
                sprintf(
                    'The return type of method "%s" in class "%s" is invalid.',
                    $method->getDeclaringClass()->getName(),
                    $method->getName()
                )
            );
        }

        if (! $type->isBuiltin()) {
            $name = '\\' . $name;
        }

        if ($type->allowsNull()
            && (null === $parameter || ! $parameter->isDefaultValueAvailable() || null !== $parameter->getDefaultValue())
        ) {
            $name = '?' . $name;
        }

        return $name;
    }

    /**
     * @param string          $template
     * @param ProxyDefinition $definition
     * @param array           $placeholders
     *
     * @return string
     */
    private function renderTemplate(string $template, ProxyDefinition $definition, array $placeholders = []) : string
    {
        preg_match_all('(<([a-zA-Z]+)>)', $template, $placeholderMatches);

        $placeholderMatches = array_combine($placeholderMatches[0], $placeholderMatches[1]);
        $replacements       = [];

        foreach ($placeholderMatches as $tag => $tagName) {
            if (isset($placeholders[$tagName]) && is_string($placeholders[$tagName])) {
                $replacements[$tag] = $placeholders[$tagName];

                continue;
            }

            $callable = $placeholders[$tagName] ?? [$this, 'generate' . ucfirst($tagName)];

            $replacements[$tag] = call_user_func($callable, $definition);
        }

        return strtr($template, $replacements);
    }
}
