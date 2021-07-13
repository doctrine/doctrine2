<?php

declare(strict_types=1);

namespace Doctrine\ORM;

use DateTimeInterface;
use Doctrine\ORM\Exception\ORMException;
use RuntimeException;

/**
 * An OptimisticLockException is thrown when a version check on an object
 * that uses optimistic locking through a version field fails.
 */
class OptimisticLockException extends ORMException
{
    /** @var object|null */
    private $entity;

    /**
     * @param string      $msg
     * @param object|null $entity
     */
    public function __construct($msg, $entity)
    {
        parent::__construct($msg);
        $this->entity = $entity;
    }

    /**
     * Gets the entity that caused the exception.
     *
     * @return object|null
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * @param object $entity
     *
     * @return OptimisticLockException
     */
    public static function lockFailed($entity)
    {
        return new self('The optimistic lock on an entity failed.', $entity);
    }

    /**
     * @param object                $entity
     * @param int|DateTimeInterface $expectedLockVersion
     * @param int|DateTimeInterface $actualLockVersion
     *
     * @return OptimisticLockException
     */
    public static function lockFailedVersionMismatch($entity, $expectedLockVersion, $actualLockVersion)
    {
        $expectedLockVersion = $expectedLockVersion instanceof DateTimeInterface ? $expectedLockVersion->getTimestamp() : $expectedLockVersion;
        $actualLockVersion   = $actualLockVersion instanceof DateTimeInterface ? $actualLockVersion->getTimestamp() : $actualLockVersion;

        return new self('The optimistic lock failed, version ' . $expectedLockVersion . ' was expected, but is actually ' . $actualLockVersion, $entity);
    }

    /**
     * @param string $entityName
     *
     * @return OptimisticLockException
     */
    public static function notVersioned($entityName)
    {
        return new self('Cannot obtain optimistic lock on unversioned entity ' . $entityName, null);
    }
}
