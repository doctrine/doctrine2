<?php

declare(strict_types=1);

namespace Doctrine\ORM\Cache;

/**
 * Cache regions configuration
 */
class RegionsConfiguration
{
    /** @var array<string,int> */
    private $lifetimes = [];

    /** @var array<string,int> */
    private $lockLifetimes = [];

    /** @var int */
    private $defaultLifetime;

    /** @var int */
    private $defaultLockLifetime;

    /**
     * @param int $defaultLifetime
     * @param int $defaultLockLifetime
     */
    public function __construct($defaultLifetime = 3600, $defaultLockLifetime = 60)
    {
        $this->defaultLifetime     = (int) $defaultLifetime;
        $this->defaultLockLifetime = (int) $defaultLockLifetime;
    }

    /**
     * @return int
     */
    public function getDefaultLifetime()
    {
        return $this->defaultLifetime;
    }

    /**
     * @param int $defaultLifetime
     *
     * @return void
     */
    public function setDefaultLifetime($defaultLifetime)
    {
        $this->defaultLifetime = (int) $defaultLifetime;
    }

    /**
     * @return int
     */
    public function getDefaultLockLifetime()
    {
        return $this->defaultLockLifetime;
    }

    /**
     * @param int $defaultLockLifetime
     *
     * @return void
     */
    public function setDefaultLockLifetime($defaultLockLifetime)
    {
        $this->defaultLockLifetime = (int) $defaultLockLifetime;
    }

    /**
     * @param string $regionName
     *
     * @return int
     */
    public function getLifetime($regionName)
    {
        return $this->lifetimes[$regionName] ?? $this->defaultLifetime;
    }

    /**
     * @param string $name
     * @param int    $lifetime
     *
     * @return void
     */
    public function setLifetime($name, $lifetime)
    {
        $this->lifetimes[$name] = (int) $lifetime;
    }

    /**
     * @param string $regionName
     *
     * @return int
     */
    public function getLockLifetime($regionName)
    {
        return $this->lockLifetimes[$regionName] ?? $this->defaultLockLifetime;
    }

    /**
     * @param string $name
     * @param int    $lifetime
     *
     * @return void
     */
    public function setLockLifetime($name, $lifetime)
    {
        $this->lockLifetimes[$name] = (int) $lifetime;
    }
}
