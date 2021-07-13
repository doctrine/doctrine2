<?php

declare(strict_types=1);

namespace Doctrine\Tests\Mocks;

use Doctrine\ORM\Persisters\Entity\BasicEntityPersister;
use Doctrine\ORM\UnitOfWork;

use function spl_object_id;

/**
 * Mock class for UnitOfWork.
 */
class UnitOfWorkMock extends UnitOfWork
{
    /** @var array */
    private $_mockDataChangeSets = [];

    /** @var array|null */
    private $_persisterMock;

    /**
     * {@inheritdoc}
     */
    public function getEntityPersister($entityName)
    {
        return $this->_persisterMock[$entityName] ?? parent::getEntityPersister($entityName);
    }

    /**
     * {@inheritdoc}
     */
    public function & getEntityChangeSet($entity)
    {
        $oid = spl_object_id($entity);

        if (isset($this->_mockDataChangeSets[$oid])) {
            return $this->_mockDataChangeSets[$oid];
        }

        $data = parent::getEntityChangeSet($entity);

        return $data;
    }

    /* MOCK API */

    /**
     * Sets a (mock) persister for an entity class that will be returned when
     * getEntityPersister() is invoked for that class.
     */
    public function setEntityPersister(string $entityName, BasicEntityPersister $persister): void
    {
        $this->_persisterMock[$entityName] = $persister;
    }

    /**
     * {@inheritdoc}
     */
    public function setOriginalEntityData($entity, array $originalData)
    {
        $this->_originalEntityData[spl_object_id($entity)] = $originalData;
    }
}
