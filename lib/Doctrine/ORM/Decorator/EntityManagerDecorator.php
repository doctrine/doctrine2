<?php

declare(strict_types=1);

namespace Doctrine\ORM\Decorator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMapping;
use Doctrine\Persistence\ObjectManagerDecorator;

/**
 * Base class for EntityManager decorators
 */
abstract class EntityManagerDecorator extends ObjectManagerDecorator implements EntityManagerInterface
{
    /** @var EntityManagerInterface */
    protected $wrapped;

    public function __construct(EntityManagerInterface $wrapped)
    {
        $this->wrapped = $wrapped;
    }

    /**
     * {@inheritdoc}
     */
    public function getConnection()
    {
        return $this->wrapped->getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function getExpressionBuilder()
    {
        return $this->wrapped->getExpressionBuilder();
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        $this->wrapped->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function transactional($func)
    {
        return $this->wrapped->transactional($func);
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        $this->wrapped->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function rollback()
    {
        $this->wrapped->rollback();
    }

    /**
     * {@inheritdoc}
     */
    public function createQuery($dql = '')
    {
        return $this->wrapped->createQuery($dql);
    }

    /**
     * {@inheritdoc}
     */
    public function createNamedQuery($name)
    {
        return $this->wrapped->createNamedQuery($name);
    }

    /**
     * {@inheritdoc}
     */
    public function createNativeQuery($sql, ResultSetMapping $rsm)
    {
        return $this->wrapped->createNativeQuery($sql, $rsm);
    }

    /**
     * {@inheritdoc}
     */
    public function createNamedNativeQuery($name)
    {
        return $this->wrapped->createNamedNativeQuery($name);
    }

    /**
     * {@inheritdoc}
     */
    public function createQueryBuilder()
    {
        return $this->wrapped->createQueryBuilder();
    }

    /**
     * {@inheritdoc}
     */
    public function getReference($entityName, $id)
    {
        return $this->wrapped->getReference($entityName, $id);
    }

    /**
     * {@inheritdoc}
     */
    public function getPartialReference($entityName, $identifier)
    {
        return $this->wrapped->getPartialReference($entityName, $identifier);
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        $this->wrapped->close();
    }

    /**
     * {@inheritdoc}
     */
    public function copy($entity, $deep = false)
    {
        return $this->wrapped->copy($entity, $deep);
    }

    /**
     * {@inheritdoc}
     */
    public function lock($entity, $lockMode, $lockVersion = null)
    {
        $this->wrapped->lock($entity, $lockMode, $lockVersion);
    }

    /**
     * {@inheritdoc}
     */
    public function find($className, $id, $lockMode = null, $lockVersion = null)
    {
        return $this->wrapped->find($className, $id, $lockMode, $lockVersion);
    }

    /**
     * {@inheritdoc}
     */
    public function flush($entity = null)
    {
        $this->wrapped->flush($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function getEventManager()
    {
        return $this->wrapped->getEventManager();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration()
    {
        return $this->wrapped->getConfiguration();
    }

    /**
     * {@inheritdoc}
     */
    public function isOpen()
    {
        return $this->wrapped->isOpen();
    }

    /**
     * {@inheritdoc}
     */
    public function getUnitOfWork()
    {
        return $this->wrapped->getUnitOfWork();
    }

    /**
     * {@inheritdoc}
     */
    public function getHydrator($hydrationMode)
    {
        return $this->wrapped->getHydrator($hydrationMode);
    }

    /**
     * {@inheritdoc}
     */
    public function newHydrator($hydrationMode)
    {
        return $this->wrapped->newHydrator($hydrationMode);
    }

    /**
     * {@inheritdoc}
     */
    public function getProxyFactory()
    {
        return $this->wrapped->getProxyFactory();
    }

    /**
     * {@inheritdoc}
     */
    public function getFilters()
    {
        return $this->wrapped->getFilters();
    }

    /**
     * {@inheritdoc}
     */
    public function isFiltersStateClean()
    {
        return $this->wrapped->isFiltersStateClean();
    }

    /**
     * {@inheritdoc}
     */
    public function hasFilters()
    {
        return $this->wrapped->hasFilters();
    }

    /**
     * {@inheritdoc}
     */
    public function getCache()
    {
        return $this->wrapped->getCache();
    }
}
