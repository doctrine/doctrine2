<?php

declare(strict_types=1);

namespace Doctrine\ORM\Proxy;

use Closure;
use Doctrine\Common\Proxy\AbstractProxyFactory;
use Doctrine\Common\Proxy\Proxy as BaseProxy;
use Doctrine\Common\Proxy\ProxyDefinition;
use Doctrine\Common\Proxy\ProxyGenerator;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\Persistence\Mapping\ClassMetadata;

/**
 * This factory is used to create proxy objects for entities at runtime.
 *
 * @deprecated 2.7 This class is being removed from the ORM and won't have any replacement
 */
class ProxyFactory extends AbstractProxyFactory
{
    /** @var EntityManagerInterface The EntityManager this factory is bound to. */
    private $em;

    /** @var UnitOfWork The UnitOfWork this factory uses to retrieve persisters */
    private $uow;

    /** @var string */
    private $proxyNs;

    /**
     * The IdentifierFlattener used for manipulating identifiers
     *
     * @var IdentifierFlattener
     */
    private $identifierFlattener;

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>EntityManager</tt>.
     *
     * @param EntityManagerInterface $em           The EntityManager the new factory works for.
     * @param string                 $proxyDir     The directory to use for the proxy classes. It must exist.
     * @param string                 $proxyNs      The namespace to use for the proxy classes.
     * @param bool|int               $autoGenerate The strategy for automatically generating proxy classes. Possible
     *               values are constants of Doctrine\Common\Proxy\AbstractProxyFactory.
     */
    public function __construct(EntityManagerInterface $em, $proxyDir, $proxyNs, $autoGenerate = AbstractProxyFactory::AUTOGENERATE_NEVER)
    {
        $proxyGenerator = new ProxyGenerator($proxyDir, $proxyNs);

        $proxyGenerator->setPlaceholder('baseProxyInterface', Proxy::class);
        parent::__construct($proxyGenerator, $em->getMetadataFactory(), $autoGenerate);

        $this->em                  = $em;
        $this->uow                 = $em->getUnitOfWork();
        $this->proxyNs             = $proxyNs;
        $this->identifierFlattener = new IdentifierFlattener($this->uow, $em->getMetadataFactory());
    }

    /**
     * {@inheritDoc}
     */
    protected function skipClass(ClassMetadata $metadata)
    {
        return $metadata->isMappedSuperclass
            || $metadata->isEmbeddedClass
            || $metadata->getReflectionClass()->isAbstract();
    }

    /**
     * {@inheritDoc}
     */
    protected function createProxyDefinition($className)
    {
        $classMetadata   = $this->em->getClassMetadata($className);
        $entityPersister = $this->uow->getEntityPersister($className);

        return new ProxyDefinition(
            ClassUtils::generateProxyClassName($className, $this->proxyNs),
            $classMetadata->getIdentifierFieldNames(),
            $classMetadata->getReflectionProperties(),
            $this->createInitializer($classMetadata, $entityPersister),
            $this->createCloner($classMetadata, $entityPersister)
        );
    }

    /**
     * Creates a closure capable of initializing a proxy
     *
     * @psalm-return Closure(BaseProxy):void
     *
     * @throws EntityNotFoundException
     */
    private function createInitializer(ClassMetadata $classMetadata, EntityPersister $entityPersister): Closure
    {
        $wakeupProxy = $classMetadata->getReflectionClass()->hasMethod('__wakeup');

        return function (BaseProxy $proxy) use ($entityPersister, $classMetadata, $wakeupProxy): void {
            $initializer = $proxy->__getInitializer();
            $cloner      = $proxy->__getCloner();

            $proxy->__setInitializer(null);
            $proxy->__setCloner(null);

            if ($proxy->__isInitialized()) {
                return;
            }

            $properties = $proxy->__getLazyProperties();

            foreach ($properties as $propertyName => $property) {
                if (! isset($proxy->$propertyName)) {
                    $proxy->$propertyName = $properties[$propertyName];
                }
            }

            $proxy->__setInitialized(true);

            if ($wakeupProxy) {
                $proxy->__wakeup();
            }

            $identifier = $classMetadata->getIdentifierValues($proxy);

            if ($entityPersister->loadById($identifier, $proxy) === null) {
                $proxy->__setInitializer($initializer);
                $proxy->__setCloner($cloner);
                $proxy->__setInitialized(false);

                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $this->identifierFlattener->flattenIdentifier($classMetadata, $identifier)
                );
            }
        };
    }

    /**
     * Creates a closure capable of finalizing state a cloned proxy
     *
     * @psalm-return Closure(BaseProxy):void
     *
     * @throws EntityNotFoundException
     */
    private function createCloner(ClassMetadata $classMetadata, EntityPersister $entityPersister): Closure
    {
        return function (BaseProxy $proxy) use ($entityPersister, $classMetadata): void {
            if ($proxy->__isInitialized()) {
                return;
            }

            $proxy->__setInitialized(true);
            $proxy->__setInitializer(null);

            $class      = $entityPersister->getClassMetadata();
            $identifier = $classMetadata->getIdentifierValues($proxy);
            $original   = $entityPersister->loadById($identifier);

            if ($original === null) {
                throw EntityNotFoundException::fromClassNameAndIdentifier(
                    $classMetadata->getName(),
                    $this->identifierFlattener->flattenIdentifier($classMetadata, $identifier)
                );
            }

            foreach ($class->getReflectionProperties() as $property) {
                if (! $class->hasField($property->name) && ! $class->hasAssociation($property->name)) {
                    continue;
                }

                $property->setAccessible(true);
                $property->setValue($proxy, $property->getValue($original));
            }
        };
    }
}
